<?php

/**
 * @file plugins/reports/counter/classes/LegacyJR1.inc.php
 *
 * Copyright (c) 2014 University of Pittsburgh
 * Distributed under the GNU GPL v2 or later. For full terms see the file docs/COPYING.
 *
 * @class LegacyJR1
 * @ingroup plugins_reports_counter
 *
 * @brief The Legacy COUNTER JR1 (r3) report
 */

use APP\template\TemplateManager;

use PKP\statistics\PKPStatisticsHelper;

class LegacyJR1
{
    /**
     * @var Plugin The COUNTER report plugin.
     */
    public $_plugin;

    /**
     * Constructor
     *
     * @param Plugin $plugin
     */
    public function LegacyJR1($plugin)
    {
        $this->_plugin = $plugin;
    }

    /**
     * Display the JR1 (R3) report
     *
     * @param PKPRequest $request
     */
    public function display($request)
    {
        $oldStats = (bool) $request->getUserVar('useOldCounterStats');
        $year = (string) $request->getUserVar('year');
        $type = (string) $request->getUserVar('type');
        switch ($type) {
            case 'report':
                $this->_report($request, $year, $oldStats);
                break;
            case 'reportxml':
                $this->_reportXml($request, $year, $oldStats);
                break;
        }
    }

    /**
     * Generate a report file.
     *
     * @param PKPRequest $request
     * @param string $year
     * @param bool $useLegacyStats Use the old counter plugin data.
     */
    public function _report($request, $year, $useLegacyStats)
    {
        $server = $request->getContext();
        [$begin, $end] = $this->_getLimitDates($year);

        header('content-type: text/comma-separated-values');
        header('content-disposition: attachment; filename=counter-' . date('Ymd') . '.csv');

        $fp = fopen('php://output', 'wt');
        fputcsv($fp, [__('plugins.reports.counter.1a.title1')]);
        fputcsv($fp, [__('plugins.reports.counter.1a.title2', ['year' => $year])]);
        fputcsv($fp, []); // FIXME: Criteria should be here?
        fputcsv($fp, [__('plugins.reports.counter.1a.dateRun')]);
        fputcsv($fp, [date('Y-m-d')]);

        $cols = [
            '',
            __('plugins.reports.counter.1a.publisher'),
            __('plugins.reports.counter.1a.platform'),
            __('plugins.reports.counter.1a.printIssn'),
            __('plugins.reports.counter.1a.onlineIssn')
        ];
        for ($i = 1; $i <= 12; $i++) {
            $time = strtotime($year . '-' . $i . '-01');
            $cols[] = date('M-Y', $time);
        }

        $cols[] = __('plugins.reports.counter.1a.ytdTotal');
        $cols[] = __('plugins.reports.counter.1a.ytdHtml');
        $cols[] = __('plugins.reports.counter.1a.ytdPdf');
        fputcsv($fp, $cols);

        // Display the totals first
        $totals = $this->_getMonthlyTotalRange($begin, $end, $useLegacyStats);
        $cols = [
            __('plugins.reports.counter.1a.totalForAllServers'),
            '-', // Publisher
            '', // Platform
            '-',
            '-'
        ];
        $this->_formColumns($cols, $totals);
        fputcsv($fp, $cols);

        // Get statistics from the log.
        $serverDao = DAORegistry::getDAO('ServerDAO'); /** @var ServerDAO $serverDao */
        $serverIds = $this->_getServerIds($useLegacyStats);
        foreach ($serverIds as $serverId) {
            $server = $serverDao->getById($serverId);
            if (!$server) {
                continue;
            }
            $entries = $this->_getMonthlyLogRange($serverId, $begin, $end, $useLegacyStats);
            $cols = [
                $server->getLocalizedName(),
                $server->getData('publisherInstitution'),
                __('common.software'), // Platform
                $server->getData('printIssn'),
                $server->getData('onlineIssn')
            ];
            $this->_formColumns($cols, $entries);
            fputcsv($fp, $cols);
            unset($server, $entry);
        }

        fclose($fp);
    }

    /**
    * Internal function to form some of the CSV columns
    *
     * @param array $cols by reference
     * @param array $entries
     * $cols will be modified
     */
    public function _formColumns(&$cols, $entries)
    {
        $allMonthsTotal = 0;
        $currTotal = 0;
        $htmlTotal = 0;
        $pdfTotal = 0;
        for ($i = 1; $i <= 12; $i++) {
            $currTotal = 0;
            foreach ($entries as $entry) {
                $month = (int) substr($entry[PKPStatisticsHelper::STATISTICS_DIMENSION_MONTH], 4, 2);
                if ($i == $month) {
                    $metric = $entry[PKPStatisticsHelper::STATISTICS_METRIC];
                    $currTotal += $metric;
                    if ($entry[PKPStatisticsHelper::STATISTICS_DIMENSION_FILE_TYPE] == PKPStatisticsHelper::STATISTICS_FILE_TYPE_HTML) {
                        $htmlTotal += $metric;
                    } elseif ($entry[PKPStatisticsHelper::STATISTICS_DIMENSION_FILE_TYPE] == PKPStatisticsHelper::STATISTICS_FILE_TYPE_PDF) {
                        $pdfTotal += $metric;
                    }
                }
            }
            $cols[] = $currTotal;
            $allMonthsTotal += $currTotal;
        }
        $cols[] = $allMonthsTotal;
        $cols[] = $htmlTotal;
        $cols[] = $pdfTotal;
    }

    /**
     * Internal function to assign information for the Counter part of a report
     *
     * @param PKPRequest $request
     * @param PKPTemplateManager $templateManager
     * @param string $begin
     * @param string $end
     * @param bool $useLegacyStats
     */
    public function _assignTemplateCounterXML($request, $templateManager, $begin, $end = '', $useLegacyStats)
    {
        $server = $request->getContext();

        $serverDao = DAORegistry::getDAO('ServerDAO'); /** @var ServerDAO $serverDao */
        $serverIds = $this->_getServerIds($useLegacyStats);

        $site = $request->getSite();
        $availableContexts = $serverDao->getAvailable();
        if ($availableContexts->getCount() > 1) {
            $vendorName = $site->getLocalizedTitle();
        } else {
            $vendorName = $server->getData('publisherInstitution');
            if (empty($vendorName)) {
                $vendorName = $server->getLocalizedName();
            }
        }

        if ($end == '') {
            $end = $begin;
        }

        $i = 0;

        foreach ($serverIds as $serverId) {
            $server = $serverDao->getById($serverId);
            if (!$server) {
                continue;
            }
            $entries = $this->_getMonthlyLogRange($serverId, $begin, $end, $useLegacyStats);

            $serversArray[$i]['entries'] = $this->_arrangeEntries($entries);
            $serversArray[$i]['serverTitle'] = $server->getLocalizedName();
            $serversArray[$i]['publisherInstitution'] = $server->getData('publisherInstitution');
            $serversArray[$i]['printIssn'] = $server->getData('printIssn');
            $serversArray[$i]['onlineIssn'] = $server->getData('onlineIssn');
            $i++;
        }

        $base_url = Config::getVar('general', 'base_url');

        $reqUser = $request->getUser();
        if ($reqUser) {
            $templateManager->assign('reqUserName', $reqUser->getUsername());
            $templateManager->assign('reqUserId', $reqUser->getId());
        } else {
            $templateManager->assign('reqUserName', __('plugins.reports.counter.1a.anonymous'));
            $templateManager->assign('reqUserId', '');
        }

        $templateManager->assign('serversArray', $serversArray);

        $templateManager->assign('vendorName', $vendorName);
        $templateManager->assign('base_url', $base_url);
    }

    /**
    * Internal function to collect structures for output
    *
     * @param array $entries
     */
    public function _arrangeEntries($entries)
    {
        $ret = [];

        $i = 0;

        foreach ($entries as $entry) {
            $year = substr($entry['month'], 0, 4);
            $month = substr($entry['month'], 4, 2);
            $start = date('Y-m-d', mktime(0, 0, 0, $month, 1, $year));
            $end = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));

            $rangeExists = false;
            foreach ($ret as $key => $record) {
                if ($record['start'] == $start && $record['end'] == $end) {
                    $rangeExists = true;
                    break;
                }
            }

            if (!$rangeExists) {
                $workingKey = $i;
                $i++;

                $ret[$workingKey]['start'] = $start;
                $ret[$workingKey]['end'] = $end;
            } else {
                $workingKey = $key;
            }

            if (array_key_exists('count_total', $ret[$workingKey])) {
                $totalCount = $ret[$workingKey]['count_total'];
            } else {
                $totalCount = 0;
            }
            $ret[$workingKey]['count_total'] = $entry[PKPStatisticsHelper::STATISTICS_METRIC] + $totalCount;
            if ($entry[PKPStatisticsHelper::STATISTICS_DIMENSION_FILE_TYPE] == PKPStatisticsHelper::STATISTICS_FILE_TYPE_HTML) {
                $ret[$workingKey]['count_html'] = $entry[PKPStatisticsHelper::STATISTICS_METRIC];
            } elseif ($entry[PKPStatisticsHelper::STATISTICS_DIMENSION_FILE_TYPE] == PKPStatisticsHelper::STATISTICS_FILE_TYPE_PDF) {
                $ret[$workingKey]['count_pdf'] = $entry[PKPStatisticsHelper::STATISTICS_METRIC];
            }
        }

        return $ret;
    }

    /**
     * Return the begin and end dates
     * based on the passed year.
     *
     * @param string $year
     *
     * @return array
     */
    public function _getLimitDates($year)
    {
        $begin = "${year}-01-01";
        $end = "${year}-12-01";

        return [$begin, $end];
    }

    /**
     * Get the valid server IDs for which log entries exist in the DB.
     *
     * @param bool $useLegacyStats Use the old counter plugin data.
     *
     * @return array
     */
    public function _getServerIds($useLegacyStats = false)
    {
        $metricsDao = DAORegistry::getDAO('MetricsDAO'); /** @var MetricsDAO $metricsDao */
        if ($useLegacyStats) {
            $results = $metricsDao->getMetrics(OPS_METRIC_TYPE_LEGACY_COUNTER, [PKPStatisticsHelper::STATISTICS_DIMENSION_ASSOC_ID]);
            $fieldId = PKPStatisticsHelper::STATISTICS_DIMENSION_ASSOC_ID;
        } else {
            $filter = [PKPStatisticsHelper::STATISTICS_DIMENSION_ASSOC_TYPE => ASSOC_TYPE_SUBMISSION_FILE];
            $results = $metricsDao->getMetrics(METRIC_TYPE_COUNTER, [PKPStatisticsHelper::STATISTICS_DIMENSION_CONTEXT_ID], $filter);
            $fieldId = PKPStatisticsHelper::STATISTICS_DIMENSION_CONTEXT_ID;
        }
        $serverIds = [];
        foreach ($results as $record) {
            $serverIds[] = $record[$fieldId];
        }
        return $serverIds;
    }

    /**
     * Retrieve a monthly log entry range.
     *
     * @param int $serverId
     * @param string $begin
     * @param string $end
     * @param bool $useLegacyStats Use the old counter plugin data.
     *
     * @return 2D array
     */
    public function _getMonthlyLogRange($serverId = null, $begin, $end, $useLegacyStats = false)
    {
        $begin = date('Ym', strtotime($begin));
        $end = date('Ym', strtotime($end));

        $metricsDao = DAORegistry::getDAO('MetricsDAO'); /** @var MetricsDAO $metricsDao */
        $columns = [PKPStatisticsHelper::STATISTICS_DIMENSION_MONTH, PKPStatisticsHelper::STATISTICS_DIMENSION_FILE_TYPE];
        $filter = [
            PKPStatisticsHelper::STATISTICS_DIMENSION_MONTH => ['from' => $begin, 'to' => $end]
        ];

        if ($useLegacyStats) {
            $dimension = PKPStatisticsHelper::STATISTICS_DIMENSION_ASSOC_ID;
            $metricType = OPS_METRIC_TYPE_LEGACY_COUNTER;
        } else {
            $dimension = PKPStatisticsHelper::STATISTICS_DIMENSION_CONTEXT_ID;
            $metricType = METRIC_TYPE_COUNTER;
            $filter[PKPStatisticsHelper::STATISTICS_DIMENSION_ASSOC_TYPE] = ASSOC_TYPE_SUBMISSION_FILE;
        }

        if ($serverId) {
            $columns[] = $dimension;
            $filter[$dimension] = $serverId;
        }

        $results = $metricsDao->getMetrics($metricType, $columns, $filter);
        return $results;
    }

    /**
     * Retrieve a monthly log entry range.
     *
     * @param string $begin
     * @param string $end
     * @param bool $useLegacyStats Use the old counter plugin data.
     *
     * @return array 2D array
     */
    public function _getMonthlyTotalRange($begin, $end, $useLegacyStats = false)
    {
        return $this->_getMonthlyLogRange(null, $begin, $end, $useLegacyStats);
    }

    /**
     *
     * Counter report in XML
     *
     * @param PKPRequest $request
     * @param string $year
     * @param bool $useLegacyStats
     */
    public function _reportXML($request, $year, $useLegacyStats)
    {
        $templateManager = TemplateManager::getManager();
        [$begin, $end] = $this->_getLimitDates($year);

        $this->_assignTemplateCounterXML($request, $templateManager, $begin, $end, $useLegacyStats);
        $reportContents = $templateManager->fetch($this->_plugin->getTemplateResource('reportxml.tpl'));
        header('Content-type: text/xml');
        echo $reportContents;
    }
}
