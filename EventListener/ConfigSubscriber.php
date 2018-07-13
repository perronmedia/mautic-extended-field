<?php

/*
 * @copyright   2018 Mautic Contributors. All rights reserved
 * @author      Digital Media Solutions, LLC
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticExtendedFieldBundle\EventListener;

use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigBuilderEvent;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\ReportBundle\Event\ReportGraphEvent;
use Mautic\ReportBundle\ReportEvents;

/**
 * Class ConfigSubscriber.
 */
class ConfigSubscriber extends CommonSubscriber
{
    /**
     * @var
     */
    protected $event;

    /**
     * @var
     */
    protected $query;

    /**
     * @var
     */
    protected $fieldModel;

    /**
     * @var array
     */
    protected $selectParts = [];

    /**
     * @var array
     */
    protected $orderByParts = [];

    /**
     * @var array
     */
    protected $groupByParts = [];

    /**
     * @var array
     */
    protected $filters = [];

    /**
     * @var
     */
    protected $where;

    /**
     * @var array
     */
    protected $extendedFields = [];

    /**
     * @var array
     */
    protected $fieldTables = [];

    /**
     * @var int
     */
    protected $count = 0;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        $eventList = [
            ConfigEvents::CONFIG_ON_GENERATE       => ['onConfigGenerate', 0],
            ReportEvents::REPORT_ON_GRAPH_GENERATE => ['onReportGraphGenerate', 20],
            ReportEvents::REPORT_QUERY_PRE_EXECUTE => ['onReportQueryPreExecute'],
        ];

        return $eventList;
    }

    /**
     * @param ConfigBuilderEvent $event
     */
    public function onConfigGenerate(ConfigBuilderEvent $event)
    {
        $params = !empty(
        $event->getParametersFromConfig(
            'MauticExtendedFieldBundle'
        )
        ) ? $event->getParametersFromConfig('MauticExtendedFieldBundle') : [];
        $event->addForm(
            [
                'bundle'     => 'MauticExtendedFieldBundle',
                'formAlias'  => 'extendedField_config',
                'formTheme'  => 'MauticExtendedFieldBundle:Config',
                'parameters' => $params,
            ]
        );
    }

    /**
     * @param $event
     */
    public function onReportQueryPreExecute($event)
    {
        $this->fieldTables = [];
        $this->event       = $event;
        $this->query       = $event->getQuery();
        $this->convertToExtendedFieldQuery();
        $this->event->setQuery($this->query);
    }

    /**
     * helper method to convert queries with extendedField optins
     * in select, orderBy and GroupBy to work with the
     * extendedField schema.
     */
    private function convertToExtendedFieldQuery()
    {
        $this->fieldModel   = $this->dispatcher->getContainer()->get('mautic.lead.model.field');
        $this->selectParts  = $this->query->getQueryPart('select');
        $this->orderByParts = $this->query->getQueryPart('orderBy');
        $this->groupByParts = $this->query->getQueryPart('groupBy');
        $this->filters      = $this->event->getReport()->getFilters();
        $this->where        = $this->query->getQueryPart('where');
        $this->fieldTables  = isset($this->fieldTables) ? $this->fieldTables : [];
        $this->count        = 0;
        if (!$this->extendedFields) {
            // Previous method deprecated:
            // $fields = $this->fieldModel->getEntities(
            //     [
            //         [
            //             'column' => 'f.isPublished',
            //             'expr'   => 'eq',
            //             'value'  => true,
            //         ],
            //         'force'          => [
            //             'column' => 'f.object',
            //             'expr'   => 'in',
            //             'value'  => ['extendedField', 'extendedFieldSecure'],
            //         ],
            //         'hydration_mode' => 'HYDRATE_ARRAY',
            //     ]
            // );
            // // Key by alias.
            // foreach ($fields as $field) {
            //     $this->extendedFields[$field['alias']] = $field;
            // }
            $this->extendedFields = $this->fieldModel->getExtendedFields();
        }

        $this->alterSelect();
        if (method_exists($this->event, 'getQuery')) { // identify ReportQueryEvent instance in backwards compatible way
            $this->alterOrderBy();
        }
        $this->alterGroupBy();
        $this->alterWhere();

        $this->query->select($this->selectParts);
        if (method_exists($this->event, 'getQuery') && !empty($this->orderByParts)) {
            $orderBy = implode(',', $this->orderByParts);
            $this->query->add('orderBy', $orderBy);
        }
        if (!empty($this->groupByParts)) {
            $this->query->groupBy($this->groupByParts);
        }
        $this->query->where($this->where);
    }

    /**
     * @return mixed
     */
    private function alterSelect()
    {
        foreach ($this->selectParts as $key => $selectPart) {
            if (0 === strpos($selectPart, 'l.')) {
                // field from the lead table, so check if its an extended field
                $partStrings = (explode(' AS ', $selectPart));
                if (method_exists($this->event, 'getQuery')) {
                    $fieldAlias = $this->event->getOptions()['columns'][$partStrings[0]]['alias'];
                } else {
                    $fieldAlias = $partStrings[1];
                }

                if (isset($this->extendedFields[$fieldAlias])) {
                    // is extended field, so rewrite the SQL part.
                    $dataType  = $this->fieldModel->getSchemaDefinition(
                        $this->extendedFields[$fieldAlias]['alias'],
                        $this->extendedFields[$fieldAlias]['type']
                    );
                    $dataType  = $dataType['type'];
                    $secure    = 'extendedFieldSecure' === $this->extendedFields[$fieldAlias]['object'] ? '_secure' : '';
                    $tableName = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$dataType.$secure.'_xref';
                    ++$this->count;
                    $fieldId = $this->extendedFields[$fieldAlias]['id'];

                    if (array_key_exists($fieldAlias, $this->fieldTables)) {
                        $this->selectParts[$key] = $this->fieldTables[$fieldAlias]['alias'].'.value AS '.$fieldAlias;
                    } else {
                        $this->selectParts[$key] = "t$this->count.value AS $fieldAlias";

                        $this->fieldTables[$fieldAlias] = [
                            'table' => $tableName,
                            'alias' => 't'.$this->count,
                        ];
                        $this->query->leftJoin(
                            'l',
                            $tableName,
                            't'.$this->count,
                            'l.id = t'.$this->count.'.lead_id AND t'.$this->count.'.lead_field_id = '.$fieldId
                        );
                    }
                }
            }
        }
    }

    /**
     * @return mixed
     */
    private function alterOrderBy()
    {
        foreach ($this->orderByParts as $key => $orderByPart) {
            if (0 === strpos($orderByPart, 'l.')) {
                // field from the lead table, so check if its an extended field
                $partStrings = (explode(' ', $orderByPart));
                $fieldAlias  = substr($partStrings[0], 2);

                if (isset($this->extendedFields[$fieldAlias])) {
                    // is extended field, so rewrite the SQL part.
                    if (array_key_exists($fieldAlias, $this->fieldTables)) {
                        // set using the existing table alias from the previously altered select statement
                        $this->orderByParts[$key] = $fieldAlias;
                    } else {
                        // field hasnt been identified yet
                        // add a join statement
                        $dataType  = $this->fieldModel->getSchemaDefinition(
                            $this->extendedFields[$fieldAlias]['alias'],
                            $this->extendedFields[$fieldAlias]['type']
                        );
                        $dataType  = $dataType['type'];
                        $secure    = 'extendedFieldSecure' === $this->extendedFields[$fieldAlias]['object'] ? '_secure' : '';
                        $tableName = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$dataType.$secure.'_xref';
                        ++$this->count;
                        $fieldId = $this->extendedFields[$fieldAlias]['id'];

                        $this->fieldTables[$fieldAlias] = [
                            'table' => $tableName,
                            'alias' => 't'.$this->count,
                        ];
                        $this->query->leftJoin(
                            'l',
                            $tableName,
                            't'.$this->count,
                            'l.id = t'.$this->count.'.lead_id AND t'.$this->count.'.lead_field_id = '.$fieldId
                        );
                        $this->orderByParts[$key] = 't'.$this->count.'.value';
                    }
                }
            }
        }
    }

    /**
     * @return mixed
     */
    private function alterGroupBy()
    {
        foreach ($this->groupByParts as $key => $groupByPart) {
            if (0 === strpos($groupByPart, 'l.')) {
                // field from the lead table, so check if its an extended
                $fieldAlias = substr($groupByPart, 2);
                if (isset($this->extendedFields[$fieldAlias])) {
                    // is extended field, so rewrite the SQL part.
                    if (array_key_exists($fieldAlias, $this->fieldTables)) {
                        // set using the existing table alias from the altered select statement
                        $this->groupByParts[$key] = $this->fieldTables[$fieldAlias]['alias'].'.value';
                    } else {
                        // field hasnt been identified yet so generate unique alias and table
                        $dataType  = $this->fieldModel->getSchemaDefinition(
                            $this->extendedFields[$fieldAlias]['alias'],
                            $this->extendedFields[$fieldAlias]['type']
                        );
                        $dataType  = $dataType['type'];
                        $secure    = 'extendedFieldSecure' === $this->extendedFields[$fieldAlias]['object'] ? '_secure' : '';
                        $tableName = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$dataType.$secure.'_xref';
                        ++$this->count;
                        $fieldId = $this->extendedFields[$fieldAlias]['id'];

                        $this->fieldTables[$fieldAlias] = [
                            'table' => $tableName,
                            'alias' => 't'.$this->count,
                        ];
                        $this->query->leftJoin(
                            'l',
                            $tableName,
                            't'.$this->count,
                            'l.id = t'.$this->count.'.lead_id AND t'.$this->count.'.lead_field_id = '.$fieldId
                        );
                        $this->groupByParts[$key] = 't'.$this->count.'.value';
                    }
                }
            }
        }
    }

    private function alterWhere()
    {
        $where = $this->where->__toString();
        foreach ($this->filters as $filter) {
            if (0 === strpos($filter['column'], 'l.')) {
                // field from the lead table, so check if its an extended
                $fieldAlias = substr($filter['column'], 2);
                if (isset($this->extendedFields[$fieldAlias])) {
                    // is extended field, so rewrite the SQL part.
                    if (array_key_exists($fieldAlias, $this->fieldTables)) {
                        // set using the existing table alias from the altered select statement
                        $where = str_replace(
                            $filter['column'],
                            $this->fieldTables[$fieldAlias]['alias'].'.value',
                            $where
                        );
                    } else {
                        // field hasnt been identified yet so generate unique alias and table
                        $dataType  = $this->fieldModel->getSchemaDefinition(
                            $this->extendedFields[$fieldAlias]['alias'],
                            $this->extendedFields[$fieldAlias]['type']
                        );
                        $dataType  = $dataType['type'];
                        $secure    = 'extendedFieldSecure' === $this->extendedFields[$fieldAlias]['object'] ? '_secure' : '';
                        $tableName = MAUTIC_TABLE_PREFIX.'lead_fields_leads_'.$dataType.$secure.'_xref';
                        ++$this->count;
                        $fieldId = $this->extendedFields[$fieldAlias]['id'];

                        $this->fieldTables[$fieldAlias] = [
                            'table' => $tableName,
                            'alias' => 't'.$this->count,
                        ];
                        $this->query->leftJoin(
                            'l',
                            $tableName,
                            't'.$this->count,
                            'l.id = t'.$this->count.'.lead_id AND t'.$this->count.'.lead_field_id = '.$fieldId
                        );
                        $where = str_replace($filter['column'], 't'.$this->count.'.value', $where);
                    }
                }
            }
        }
        $this->where = $where;
    }

    /**
     * @param ReportGraphEvent $event
     */
    public function onReportGraphGenerate(ReportGraphEvent $event)
    {
        $this->fieldTables = [];
        $this->event       = $event;
        $this->query       = $event->getQueryBuilder();
        $this->convertToExtendedFieldQuery();
        $this->event->setQueryBuilder($this->query);
    }
}
