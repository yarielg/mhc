<?php
/*
* Controller for generating Slim PDF with the company logo
*/

namespace Mhc\Inc\Controllers;

require_once(dirname(__DIR__, 2) . '/vendor/tecnickcom/tcpdf/tcpdf.php');

/**
 * Controller for generating payroll and worker slip PDFs using TCPDF.
 * Provides AJAX endpoints for PDF generation and access control for logged-in users.
 */
class PdfController
{
    /**
     * Checks AJAX access for logged-in users only.
     * Calls mhc_check_ajax_access() defined in util/helpers.php.
     *
     * @return void
     */
    private static function check()
    {
        if (!function_exists('mhc_check_ajax_access')) {
            require_once dirname(__DIR__, 2) . '/util/helpers.php';
        }
        mhc_check_ajax_access();
    }
    /**
     * AJAX endpoint to display the worker slip PDF for a payroll.
     * Requires GET parameters: payroll_id and worker_id.
     * Generates and outputs the PDF in the browser.
     *
     * @return void
     */
    public static function ajax_worker_slip_pdf()
    {
        self::check();
        $payroll_id = (int)($_GET['payroll_id'] ?? 0);
        $worker_id  = (int)($_GET['worker_id'] ?? 0);
        if ($payroll_id <= 0 || $worker_id <= 0) {
            status_header(400);
            echo 'Faltan payroll_id o worker_id';
            exit;
        }

        $worker_name = '';
        if (!empty($hours)) $worker_name = $hours[0]->worker_name ?? '';
        if ($worker_name === '') {
            global $wpdb;
            $t = $wpdb->prefix . 'mhc_workers';
            $worker_name = (string)$wpdb->get_var($wpdb->prepare("SELECT CONCAT(first_name,' ',last_name) FROM {$t} WHERE id=%d", $worker_id));
        }

        // Generate PDF with the data
        $pdfPath = self::generateWorkerSlipPdf([
            'payroll_id' => $payroll_id,
            'worker_id' => $worker_id,
            'worker_name' => $worker_name,
        ]);
        if (!file_exists($pdfPath)) {
            status_header(500);
            echo 'Error generando PDF';
            exit;
        }
        
        $download_name = basename($pdfPath);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $download_name . '"');
        readfile($pdfPath);
        unlink($pdfPath);
        exit;
    }
    /**
     * Generates the worker slip PDF with hours, extras, and totals.
     *
     * @param array $data Worker and payroll data
     * @return string Path to the generated PDF file
     */
    public static function generateWorkerSlipPdf($data = [])
    {

        // get worker hours and extras
        $hours = \Mhc\Inc\Models\HoursEntry::listDetailedForPayroll($data['payroll_id'], ['worker_id' => $data['worker_id']]);
        $extras = \Mhc\Inc\Models\ExtraPayment::listDetailedForPayroll($data['payroll_id'], ['worker_id' => $data['worker_id']]);

        $th = 0.0;
        $ta = 0.0;
        $te = 0.0;
        foreach ($hours as $h) {
            $th += (float)$h->hours;
            $ta += (float)$h->total;
        }
        foreach ($extras as $e) {
            $te += (float)$e->amount;
        }

        $worker_name = '';
        if (!empty($hours)) $worker_name = $hours[0]->worker_name ?? '';
        if ($worker_name === '') {
            global $wpdb;
            $t = $wpdb->prefix . 'mhc_workers';
            $worker_name = (string)$wpdb->get_var($wpdb->prepare("SELECT CONCAT(first_name,' ',last_name) FROM {$t} WHERE id=%d", $worker_id));
        }

        $data['hours'] = $hours;
        $data['extras'] = $extras;
        $data['totals'] = [
            'total_hours'   => round($th, 2),
            'hours_amount'  => round($ta, 2),
            'extras_amount' => round($te, 2),
            'grand_total'   => round($ta + $te, 2),
        ];


        $logo = dirname(__DIR__, 2) . '/assets/img/mentalhelt.jpg';
        $pdf = new \TCPDF();
        $pdf->SetCreator('MHC Payroll');
        $pdf->SetAuthor('MHC');
        $pdf->SetTitle('Worker Slip PDF');
        $pdf->SetMargins(10, 20, 10);
        $pdf->AddPage();
        if (file_exists($logo)) {
            $pdf->Image($logo, 20, 10, 40, 0, '', '', '', false, 300);
        }
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 18, 'Worker Payroll Slip', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, 'Worker: ' . ($data['worker_name'] ?? '---'), 0, 1, 'L');
        $pdf->Ln(4);


        // Hours
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->Cell(0, 10, 'Hours', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        if (!empty($data['hours'])) {
            foreach ($data['hours'] as $h) {
                $pdf->Cell(60, 8, 'Patient: ' . ($h->patient_name ?? ''), 0, 0, 'L');
                $pdf->Cell(30, 8, 'Role: ' . ($h->role_code ?? ''), 0, 0, 'L');
                $pdf->Cell(25, 8, 'Hours: ' . ($h->hours ?? 0), 0, 0, 'L');
                $pdf->Cell(30, 8, 'Rate: $' . number_format($h->used_rate ?? 0, 2), 0, 0, 'L');
                $pdf->Cell(30, 8, 'Total: $' . number_format($h->total ?? 0, 2), 0, 1, 'L');
            }
        } else {
            $pdf->Cell(0, 8, 'No hours registered.', 0, 1, 'L');
        }
        $pdf->Ln(4);

        // Extras
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->Cell(0, 10, 'Extras', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        if (!empty($data['extras'])) {
            foreach ($data['extras'] as $e) {
                $pdf->MultiCell(50, 8, 'Code: ' . ($e->code ?? ''), 0, 'L', false, 0);
                $pdf->MultiCell(60, 8, 'Label: ' . ($e->label ?? ''), 0, 'L', false, 0);
                $pdf->MultiCell(30, 8, 'Amount: $' . number_format($e->amount ?? 0, 2), 0, 'L', false, 0);
                //$pdf->MultiCell(30, 8, 'Rate: $' . number_format($e->unit_rate ?? 0, 2), 0, 'L', false, 0);
                $pdf->MultiCell(0, 8, 'Notes: ' . ($e->notes ?? ''), 0, 'L', false, 1);
            }
        } else {
            $pdf->Cell(0, 8, 'No extras registered.', 0, 1, 'L');
        }
        $pdf->Ln(4);

        // Totals
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->Cell(0, 10, 'Totals', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        $totals = $data['totals'] ?? [];
        $pdf->Cell(50, 8, 'Total Hours: ' . number_format($totals['total_hours'] ?? 0, 2), 0, 0, 'L');
        $pdf->Cell(50, 8, 'Hours Amount: $' . number_format($totals['hours_amount'] ?? 0, 2), 0, 0, 'L');
        $pdf->Cell(50, 8, 'Extras Amount: $' . number_format($totals['extras_amount'] ?? 0, 2), 0, 0, 'L');
        $pdf->Cell(0, 8, 'Grand Total: $' . number_format($totals['grand_total'] ?? 0, 2), 0, 1, 'L');

        // Obtener fechas del payroll y nombre del worker
        $payroll = isset($data['payroll_id']) ? \Mhc\Inc\Models\Payroll::findById($data['payroll_id']) : null;
        $start = $payroll->start_date ?? date('Y-m-d');
        $end = $payroll->end_date ?? date('Y-m-d');
        $worker_name = $data['worker_name'] ?? 'worker';
        // Limpiar el nombre para archivo
        $worker_name_clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $worker_name);
        $filename = $worker_name_clean . '_' . $start . '-' . $end . '.pdf';
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
        $pdf->Output($tmp, 'F');
        return $tmp;
    }

    /**
     * Generates the Slim PDF and returns the file path (or saves it as a temporary file).
     *
     * @param array $data Data for the PDF (e.g., name, date, etc)
     * @return string Path to the generated PDF file
     */
    public static function generateSlimPdf($data = [])
    {
        $logo = dirname(__DIR__, 2) . '/assets/img/mentalhelt.jpg';
        $pdf = new \TCPDF();
        $pdf->SetCreator('MHC Payroll');
        $pdf->SetAuthor('MHC');
        $pdf->SetTitle('Slim PDF');
        $pdf->SetMargins(20, 20, 20);
        $pdf->AddPage();
        if (file_exists($logo)) {
            $pdf->Image($logo, 20, 10, 40, 0, '', '', '', false, 300);
        }
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 20, 'SLIM PDF', 0, 1, 'C');
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Write(0, 'Nombre: ' . ($data['name'] ?? '---') . "\n");
        $pdf->Write(0, 'Fecha: ' . ($data['date'] ?? date('Y-m-d')) . "\n");
        $pdf->Ln(10);
        $pdf->Write(0, 'Este es un ejemplo de PDF generado con TCPDF y el logo de la compañía.');
        $tmp = tempnam(sys_get_temp_dir(), 'slimpdf_') . '.pdf';
        $pdf->Output($tmp, 'F');
        return $tmp;
    }

    /**
     * AJAX endpoint to display the generated Slim PDF in the browser.
     * Accepts optional GET parameters: name and date.
     *
     * @return void
     */
    public static function ajax_show_slim_pdf()
    {
        self::check();
        $name = isset($_GET['name']) ? sanitize_text_field($_GET['name']) : 'Test User';
        $date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : date('Y-m-d');
        $pdfPath = self::generateSlimPdf(['name' => $name, 'date' => $date]);
        if (!file_exists($pdfPath)) {
            status_header(500);
            echo 'Error generando PDF';
            exit;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="slim.pdf"');
        readfile($pdfPath);
        unlink($pdfPath);
        exit;
    }

    /**
     * Registers the AJAX endpoints in WordPress for the PDFs.
     * Only logged-in users can access.
     *
     * @return void
     */
    public static function register()
    {
        add_action('wp_ajax_mhc_show_slim_pdf', [__CLASS__, 'ajax_show_slim_pdf']);
        //add_action('wp_ajax_nopriv_mhc_show_slim_pdf', [__CLASS__, 'ajax_show_slim_pdf']);
        add_action('wp_ajax_mhc_worker_slip_pdf', [__CLASS__, 'ajax_worker_slip_pdf']);
        //add_action('wp_ajax_nopriv_mhc_worker_slip_pdf', [__CLASS__, 'ajax_worker_slip_pdf']);
    }
}
