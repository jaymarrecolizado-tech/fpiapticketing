<?php
class HistoryExport {
    public static function toCSV($data) {
        try {
            $output = fopen('php://temp', 'w');

            // CSV headers for grouped export
            fputcsv($output, [
                'Ticket Number', 'Ticket Title', 'Change Count', 'Last Updated', 'Last Updated By', 'History Summary'
            ]);

            foreach ($data as $row) {
                if (isset($row['history_summary'])) {
                    fputcsv($output, [
                        $row['ticket_number'] ?? '',
                        $row['ticket_title'] ?? '',
                        $row['changes_count'] ?? '',
                        $row['last_updated'] ?? '',
                        $row['last_updated_by'] ?? '',
                        $row['history_summary'] ?? ''
                    ]);
                } else {
                    fputcsv($output, [
                        $row['ticket_number'] ?? '',
                        $row['fullname'] ?? '',
                        $row['action'] ?? '',
                        $row['field_name'] ?? '',
                        $row['old_value'] ?? '',
                        $row['new_value'] ?? '',
                        $row['timestamp'] ?? '',
                        $row['ip_address'] ?? ''
                    ]);
                }
            }

            rewind($output);
            $csv = stream_get_contents($output);
            fclose($output);

            return $csv;
        } catch (Exception $e) {
            error_log('HistoryExport toCSV error: ' . $e->getMessage());
            return '';
        }
    }

    public static function toPDF($data, $title = 'Ticket History Report') {
        try {
            $lines = [];
            $lines[] = $title;
            $lines[] = '';

            if (empty($data)) {
                $lines[] = 'No history data available.';
            } else {
                if (isset($data[0]['history_summary'])) {
                    $lines[] = implode(' | ', [
                        'Ticket Number',
                        'Ticket Title',
                        'Change Count',
                        'Last Updated',
                        'Last Updated By',
                        'History Summary'
                    ]);
                    $lines[] = str_repeat('-', 120);

                    foreach ($data as $row) {
                        $lines[] = implode(' | ', [
                            $row['ticket_number'] ?? '',
                            $row['ticket_title'] ?? '',
                            $row['changes_count'] ?? '',
                            $row['last_updated'] ?? '',
                            $row['last_updated_by'] ?? '',
                            str_replace("\n", ' ', $row['history_summary'] ?? '')
                        ]);
                    }
                } else {
                    $lines[] = implode(' | ', [
                        'Ticket Number',
                        'User',
                        'Action',
                        'Field',
                        'Old Value',
                        'New Value',
                        'Timestamp',
                        'IP'
                    ]);
                    $lines[] = str_repeat('-', 120);

                    foreach ($data as $row) {
                        $lines[] = implode(' | ', [
                            $row['ticket_number'] ?? '',
                            $row['fullname'] ?? '',
                            $row['action'] ?? '',
                            $row['field_name'] ?? '',
                            substr($row['old_value'] ?? '', 0, 20),
                            substr($row['new_value'] ?? '', 0, 20),
                            $row['timestamp'] ?? '',
                            $row['ip_address'] ?? ''
                        ]);
                    }
                }
            }

            return self::createSimplePdf(implode("\n", $lines));

        } catch (Exception $e) {
            error_log('HistoryExport toPDF error: ' . $e->getMessage());
            return '';
        }
    }

    private static function createSimplePdf(string $text) {
        $lines = explode("\n", $text);
        $content = "BT\n/F1 12 Tf\n72 760 Td\n";

        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $content .= "0 -14 Td\n";
            }
            $content .= '(' . self::escapePdfString($line) . ') Tj\n';
        }

        $content .= "ET\n";
        $stream = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";

        $objects = [];
        $objects[] = "%PDF-1.4\n";
        $objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[2] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $objects[3] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";
        $objects[4] = "4 0 obj\n" . $stream . "\nendobj\n";
        $objects[5] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

        $offsets = [];
        $pdf = $objects[0];
        $currentOffset = strlen($pdf);

        for ($i = 1; $i < count($objects); $i++) {
            $offsets[$i] = $currentOffset;
            $pdf .= $objects[$i];
            $currentOffset += strlen($objects[$i]);
        }

        $xref = "xref\n0 " . count($objects) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $xref .= sprintf('%010d 00000 n \n', $offset);
        }

        $pdf .= $xref;
        $pdf .= "trailer\n<< /Size " . count($objects) . " /Root 1 0 R >>\nstartxref\n" . $currentOffset . "\n%%EOF";

        return $pdf;
    }

    private static function escapePdfString(string $value) {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $value);
    }

    public static function downloadCSV($data, $filename = 'ticket_history.csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Write UTF-8 BOM for Excel compatibility
        echo "\xEF\xBB\xBF";
        $csv = self::toCSV($data);
        echo $csv;
        exit;
    }

    public static function downloadPDF($data, $filename = 'ticket_history.pdf', $title = 'Ticket History Report') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $pdf = self::toPDF($data, $title);
        echo $pdf;
        exit;
    }
}
?>