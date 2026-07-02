<?php
/**
 * PDF Generator using MPDF
 * Generates professional PDF reports for FPIAP-SMARTs
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class PdfGenerator
{
    private $mpdf;
    private $title;
    private $subtitle;

    public function __construct($title = 'FPIAP-SMARTs Report', $subtitle = '')
    {
        $this->title = $title;
        $this->subtitle = $subtitle;

        $this->mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 25,
            'margin_header' => 10,
            'margin_footer' => 10,
            'tempDir' => __DIR__ . '/../mpdf/tmp',
        ]);

        $this->mpdf->SetTitle($title);
        $this->mpdf->SetAuthor('FPIAP-SMARTs');
        $this->mpdf->SetCreator('FPIAP-SMARTs Report Generator');
    }

    /**
     * Build HTML content for a report with table data
     */
    public function buildReport($data, $options = [])
    {
        $reportName = $options['report_name'] ?? $this->title;
        $generatedAt = $options['generated_at'] ?? date('Y-m-d H:i:s');
        $filters = $options['filters'] ?? [];
        $summaryCards = $options['summary'] ?? [];

        $html = $this->getHeaderHtml($reportName, $generatedAt, $filters, $summaryCards);
        $html .= $this->getTableHtml($data, $options);
        $html .= $this->getFooterHtml();

        return $html;
    }

    /**
     * Generate and output PDF as download
     */
    public function output($html, $filename)
    {
        $this->mpdf->WriteHTML($html);
        $this->mpdf->Output($filename, Destination::INLINE);
    }

    /**
     * Generate PDF and return raw content
     */
    public function getPdfContent($html)
    {
        $this->mpdf->WriteHTML($html);
        return $this->mpdf->Output('', Destination::STRING_RETURN);
    }

    private function getHeaderHtml($reportName, $generatedAt, $filters, $summaryCards)
    {
        $filterHtml = '';
        if (!empty($filters)) {
            $filterParts = [];
            foreach ($filters as $key => $value) {
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $label = ucwords(str_replace('_', ' ', $key));
                $filterParts[] = "<span class='filter-badge'>{$label}: " . htmlspecialchars($value) . "</span>";
            }
            $filterHtml = '<div class="filters"><strong>Filters:</strong> ' . implode(' ', $filterParts) . '</div>';
        }

        $summaryHtml = '';
        if (!empty($summaryCards)) {
            $summaryHtml = '<div class="summary-cards">';
            foreach ($summaryCards as $card) {
                $summaryHtml .= "<div class='summary-card'><div class='summary-value'>" . htmlspecialchars($card['value'] ?? '0') . "</div><div class='summary-label'>" . htmlspecialchars($card['label'] ?? '') . "</div></div>";
            }
            $summaryHtml .= '</div>';
        }

        return "
        <style>
            body { font-family: 'Arial', sans-serif; font-size: 9pt; color: #333; }
            .header { margin-bottom: 15px; border-bottom: 3px solid #1a73e8; padding-bottom: 10px; }
            .header h1 { font-size: 18pt; color: #1a73e8; margin: 0 0 5px 0; }
            .header .subtitle { font-size: 10pt; color: #666; margin: 0; }
            .header .generated { font-size: 8pt; color: #999; margin-top: 5px; }
            .filters { margin: 8px 0; font-size: 8pt; }
            .filter-badge { background: #e8f0fe; color: #1a73e8; padding: 2px 8px; border-radius: 3px; margin-right: 5px; display: inline-block; margin-bottom: 3px; }
            .summary-cards { display: flex; gap: 10px; margin: 12px 0; }
            .summary-card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 8px 12px; text-align: center; min-width: 100px; }
            .summary-value { font-size: 16pt; font-weight: bold; color: #1a73e8; }
            .summary-label { font-size: 7pt; color: #666; text-transform: uppercase; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 8pt; }
            th { background-color: #1a73e8; color: white; padding: 6px 8px; text-align: left; font-weight: bold; white-space: nowrap; }
            td { padding: 5px 8px; border-bottom: 1px solid #e0e0e0; }
            tr:nth-child(even) { background-color: #f8f9fa; }
            tr:hover { background-color: #e8f0fe; }
            .status-open { color: #dc3545; font-weight: bold; }
            .status-in-progress { color: #ffc107; font-weight: bold; }
            .status-resolved { color: #17a2b8; font-weight: bold; }
            .status-closed { color: #28a745; font-weight: bold; }
            .footer { margin-top: 20px; padding-top: 10px; border-top: 1px solid #dee2e6; font-size: 7pt; color: #999; text-align: center; }
        </style>
        <div class='header'>
            <h1>" . htmlspecialchars($reportName) . "</h1>
            <p class='subtitle'>DICT Region II — FPIAP-SMARTs Ticketing System</p>
            <p class='generated'>Generated: {$generatedAt}</p>
        </div>
        {$filterHtml}
        {$summaryHtml}
        ";
    }

    private function getTableHtml($data, $options)
    {
        if (empty($data)) {
            return '<p style="text-align:center; color:#999; padding:40px;">No data available for this report.</p>';
        }

        $html = '<table>';

        // Header
        $html .= '<thead><tr>';
        $headers = $options['headers'] ?? array_keys($data[0]);
        foreach ($headers as $header) {
            $label = $options['header_labels'][$header] ?? ucwords(str_replace('_', ' ', $header));
            $html .= '<th>' . htmlspecialchars($label) . '</th>';
        }
        $html .= '</tr></thead>';

        // Body
        $html .= '<tbody>';
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                $displayValue = $this->formatCellValue($value, $header, $options);
                $html .= "<td>{$displayValue}</td>";
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    private function formatCellValue($value, $header, $options)
    {
        if ($value === null || $value === '') {
            return '<span style="color:#999;">N/A</span>';
        }

        // Status coloring
        if ($header === 'status') {
            $class = 'status-' strtolower(str_replace('_', '-', $value));
            return "<span class='{$class}'>" . htmlspecialchars($value) . '</span>';
        }

        // Numeric formatting
        if (is_numeric($value)) {
            if (strpos($header, 'rate') !== false || strpos($header, 'percent') !== false) {
                return number_format($value, 2) . '%';
            }
            if (strpos($header, 'avg') !== false) {
                return number_format($value, 2);
            }
            if (strpos($header, 'duration') !== false) {
                // Convert minutes to days/hours
                $days = floor($value / 1440);
                $hours = floor(($value % 1440) / 60);
                if ($days > 0) return "{$days}d {$hours}h";
                return "{$hours}h";
            }
        }

        // Date formatting
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$value)) {
            return date('M d, Y', strtotime($value));
        }

        // Truncate long strings
        $str = htmlspecialchars((string)$value);
        if (strlen($str) > 50) {
            return substr($str, 0, 50) . '...';
        }

        return $str;
    }

    private function getFooterHtml()
    {
        return "
        <div class='footer'>
            <p>FPIAP-SMARTs — Free Public Internet Access Program | Service Management and Response Ticketing System</p>
            <p>DICT Region II — Cagayan Valley, Philippines | This report was automatically generated.</p>
        </div>
        ";
    }

    /**
     * Get report name from type code
     */
    public static function getReportName($type)
    {
        $names = [
            '1_site_summary' => 'Site Summary Report',
            '3_isp_performance' => 'ISP Performance Report',
            '5_project_report' => 'Project Report',
            '7_aging_tickets' => 'Aging Tickets Report',
            '8_monthly_activity' => 'Monthly Activity Report',
            'ticket_summary' => 'Ticket Summary Report',
            'ticket_detail' => 'Ticket Detail Report',
        ];
        return $names[$type] ?? ucwords(str_replace('_', ' ', $type)) . ' Report';
    }
}
