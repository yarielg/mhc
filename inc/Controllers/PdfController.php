<?php
/*
* Controller for generating Slim PDF with the company logo
*/

namespace Mhc\Inc\Controllers;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;



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
    global $wpdb;

    // Datos (igual que ya lo haces)
    $hours  = \Mhc\Inc\Models\HoursEntry::listDetailedForPayroll($data['payroll_id'], ['worker_id' => $data['worker_id']]);
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

    $worker_name  = '';
    $company_name = '';
    if (!empty($hours)) {
      $worker_name  = $hours[0]->worker_name ?? '';
      $company_name = $hours[0]->worker_company ?? '';
    }
    if ($worker_name === '') {
      $t = $wpdb->prefix . 'mhc_workers';
      $row = $wpdb->get_row($wpdb->prepare(
        "SELECT CONCAT(first_name,' ',last_name) AS name, company FROM {$t} WHERE id=%d",
        $data['worker_id']
      ));
      if ($row) {
        $worker_name  = (string)$row->name;
        $company_name = (string)$row->company;
      }
    }

    $data['hours']  = $hours;
    $data['extras'] = $extras;
    $data['totals'] = [
      'total_hours'   => round($th, 2),
      'hours_amount'  => round($ta, 2),
      'extras_amount' => round($te, 2),
      'grand_total'   => round($ta + $te, 2),
    ];

    // Payroll info
    $payroll = isset($data['payroll_id']) ? \Mhc\Inc\Models\Payroll::findById($data['payroll_id']) : null;
    $start = $payroll->start_date ?? date('Y-m-d');
    $end   = $payroll->end_date   ?? date('Y-m-d');

    // Logo (ruta absoluta de archivo para mPDF)
    $logo_path = dirname(__DIR__, 2) . '/assets/img/mentalhelt.jpg';

    // ==== HTML (tu versión ajustada para diseño suave) ====
    // NOTA: mantenemos nombres/estructura de columnas y datos como pediste.
    $html = '
<style>
  body, .container { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color:#333; }
  .header { border-bottom: 2px solid #006699; padding-bottom: 10px; margin-bottom: 20px; }
  .header h2 { margin:0; color:#000; font-size: 16px; }
  .info p { margin:4px 0; font-size:11px; }
  .info strong { color:#000; }
  .section-title { font-size: 12px; font-weight: bold; color:#006699; margin: 18px 0 8px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  thead th { background:#f2f2f2; color:#000; font-weight:bold; text-align:center; border:1px solid #bbb; }
  tbody td, tfoot td { border:1px solid #bbb; }
  th, td { font-size:10px; padding:8px; }
  .footer { font-size: 9px; color:#555; text-align:center; margin-top:22px; }
  .totals td { background: #e6f0fa; font-weight: bold; }
  .totals td:first-child { text-align:left; }
  .spacer { height:10px; }
</style>

<div class="container">
  <!-- Header -->
  <div class="header">
    <table style="border:none;">
      <tr>
        <td style="width:70%; border:none;">
          ' . (file_exists($logo_path) ? '<img src="' . $logo_path . '" width="100" />' : '') . '
        </td>
        <td style="width:30%; text-align:right; border:none;">
          <h2>Worker Payroll Slip</h2>
          <div style="font-size:10px; margin-top: 1rem;">Agency of Mental Health Services</div>
        </td>
      </tr>
    </table>
  </div>

  <!-- Worker Info -->
  <div class="info">
    <p><strong>Worker:</strong> ' . htmlspecialchars($worker_name) . '</p>
    <p><strong>Company:</strong> ' . htmlspecialchars($company_name ?: "---") . '</p>
    <p><strong>Payroll Period:</strong> ' . $start . ' to ' . $end . '</p>
  </div>

  <!-- Regular Payments -->
  <div class="section-title">Regular Payments</div>
  <table>
    <thead>
      <tr>
        <th>Client</th>
        <th>Role</th>
        <th>Week</th>
        <th>Hours</th>
        <th>Rate</th>
        <th>Total</th>
      </tr>
    </thead>
    <tbody>';

    // filas Regular Payments (con zebra opcional sin cambiar nombres de campos)
    if (!empty($hours)) {
      $i = 0;
      foreach ($hours as $h) {
        $i++;
        $rowBg = ($i % 2 === 0) ? ' style="background:#fbfbfb;"' : '';
        $html .= '<tr' . $rowBg . '>
              <td>' . htmlspecialchars($h->patient_name) . '</td>
              <td>' . htmlspecialchars($h->role_code) . '</td>
              <td align="center" style="white-space:nowrap;">' . self::format_week_range($h->segment_start ?? '', $h->segment_end ?? '') . '</td>
              <td align="center">' . number_format($h->hours, 2) . '</td>
              <td align="right">$' . number_format($h->used_rate, 2) . '</td>
              <td align="right">$' . number_format($h->total, 2) . '</td>
            </tr>';
      }
    } else {
      $html .= '<tr><td colspan="6" align="center">No hours registered.</td></tr>';
    }

    $html .= '
    </tbody>
  </table>';
    if (!empty($extras)) {
      $html .= '

  <!-- Additional Payments -->
  <div class="section-title">Additional Payments</div>
  <table>
    <thead>
      <tr>
        <th>Label</th>
        <th>Applies To</th>
        <th>Amount</th>
        <th>Notes</th>
      </tr>
    </thead>
    <tbody>';


      $j = 0;
      foreach ($extras as $e) {
        $j++;
        $rowBg = ($j % 2 === 0) ? ' style="background:#fbfbfb;"' : '';
        $entity_name = '';
        if (!empty($e->supervised_worker_name)) {
          $entity_name = $e->supervised_worker_name;
        } elseif (!empty($e->patient_name)) {
          $entity_name = $e->patient_name;
        }
        $html .= '<tr' . $rowBg . '>
              <td style="white-space:nowrap;">' . htmlspecialchars($e->label) . ' ' . htmlspecialchars($e->cpt_code) . '</td>
              <td>' . htmlspecialchars($entity_name) . '</td>
              <td align="right">$' . number_format($e->amount, 2) . '</td>
              <td>' . htmlspecialchars($e->notes) . '</td>
            </tr>';
      }


      $html .= '
    </tbody>
  </table>';
    }
    $html .= '

  <!-- Totals -->
  <div class="section-title">Totals</div>
  <table>
    <tbody>
      <tr class="totals">
        <td>Total Hours</td>
        <td colspan="4" align="right">' . number_format($data["totals"]["total_hours"], 2) . '</td>
      </tr>
      <tr class="totals">
        <td>Regular Amount</td>
        <td colspan="4" align="right">$' . number_format($data["totals"]["hours_amount"], 2) . '</td>
      </tr>
      <tr class="totals">
        <td>Additional Amount</td>
        <td colspan="4" align="right">$' . number_format($data["totals"]["extras_amount"], 2) . '</td>
      </tr>
      <tr class="totals">
        <td>Grand Total</td>
        <td colspan="4" align="right">$' . number_format($data["totals"]["grand_total"], 2) . '</td>
      </tr>
    </tbody>
  </table>

  <!-- Footer -->
  <div class="footer">
    Slip generated automatically - Agency of Mental Health Services © ' . date("Y") . '
  </div>
</div>';

    // ====== mPDF ======
    // tempDir: usa una carpeta escribible (ajústala si quieres)
    $mpdf = new Mpdf([
      'mode' => 'utf-8',
      'format' => 'A4',
      'margin_left'   => 10,
      'margin_right'  => 10,
      'margin_top'    => 20,
      'margin_bottom' => 15,
      'tempDir' => WP_CONTENT_DIR . '/uploads/mpdf', // asegúrate que exista y sea escribible
    ]);
    $mpdf->SetTitle('Worker Slip PDF');
    $mpdf->SetAuthor('MHC');
    $mpdf->SetCreator('MHC Payroll');
    $mpdf->autoLangToFont = true; // para acentos/ñ

    $mpdf->WriteHTML($html);

    // Guardar en archivo temporal y devolver ruta (igual que antes)
    $worker_name_clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $worker_name);
    $filename = $worker_name_clean . '_' . $start . '-' . $end . '.pdf';
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;

    $mpdf->Output($tmp, Destination::FILE);
    return $tmp;
  }

  public static function format_week_range($start, $end)
  {
    if (!$start || !$end) return '—';
    $s = date_create($start);
    $e = date_create($end);
    if (!$s || !$e) return $start . ' – ' . $end;
    $sm = date_format($s, 'M');
    $em = date_format($e, 'M');
    $sy = date_format($s, 'Y');
    $ey = date_format($e, 'Y');
    $sd = date_format($s, 'j');
    $ed = date_format($e, 'j');
    $dash = '–';
    if ($s == $e) {
      return "$sm $sd, $sy";
    }
    if ($sm === $em && $sy === $ey) {
      return "$sm $sd$dash$ed, $sy";
    }
    if ($sy === $ey) {
      return "$sm $sd$dash$em $ed, $sy";
    }
    return "$sm $sd, $sy$dash$em $ed, $ey";
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
