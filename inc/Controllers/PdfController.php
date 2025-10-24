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
   * Registers the AJAX endpoints in WordPress for the PDFs.
   * Only logged-in users can access.
   *
   * @return void
   */
  public static function register()
  {
    add_action('wp_ajax_mhc_show_slim_pdf', [__CLASS__, 'ajax_show_slim_pdf']);
    add_action('wp_ajax_mhc_payroll_summary_pdf', [__CLASS__, 'ajax_payroll_summary_pdf']);
    add_action('wp_ajax_mhc_worker_slip_pdf', [__CLASS__, 'ajax_worker_slip_pdf']);
    add_action('wp_ajax_mhc_all_slips_pdf', [__CLASS__, 'ajax_all_slips_pdf']);
  }
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
    $check_number = '---';
    if (!empty($hours)) {
      $worker_name  = $hours[0]->worker_name ?? '';
      $company_name = $hours[0]->worker_company ?? '';
      $check_number = $hours[0]->check_number ?? '---';
    }
    if ($worker_name === '') {
      $t = $wpdb->prefix . 'mhc_workers';
      $check_number = $wpdb->get_var($wpdb->prepare(
        "SELECT check_number FROM {$wpdb->prefix}mhc_qb_checks WHERE payroll_id = %d AND worker_id = %d",
        $data['payroll_id'],
        $data['worker_id']
      ));
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

    $html = self::renderWorkerSlipHtml($data, $worker_name, $company_name, $start, $end, $check_number);

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

  /**
   * Renderiza el HTML del slip de trabajador (extraído de generateWorkerSlipPdf)
   */
  public static function renderWorkerSlipHtml($data, $worker_name, $company_name,$start, $end, $check_number = '---')
  {
    $logo_path = dirname(__DIR__, 2) . '/assets/img/mentalhelt.jpg';
    $hours = $data['hours'];
    $extras = $data['extras'];
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
    <p><strong>Payroll Period:</strong> ' . self::format_week_range($start, $end) . '</p>
    <p><strong>Check Number:</strong> ' . htmlspecialchars($check_number) . '</p>
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
              <td>' . htmlspecialchars($h->patient_record_number) . '</td>
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
        $htmlHour = '';
        if (!empty($e->supervised_worker_name)) {
          $entity_name = $e->supervised_worker_name;
        } elseif (!empty($e->patient_name)) {
          $entity_name = $e->patient_record_number;
        }
        if (isset($e->code) && in_array($e->code, ['pending_pos_hourly', 'pending_neg_hourly'])) {
          $hrs = isset($e->hours) ? (float)$e->hours : null;
          $rate = isset($e->hours_rate) ? (float)$e->hours_rate : null;
          if ($hrs !== null && $rate !== null) {
            $htmlHour = '<div style="font-size:9px; color:#006699; margin-top:2px;">(' . number_format($hrs, 2) . ' hrs × $' . number_format($rate, 2) . ')</div>';
          }
        }

        $html .= '<tr' . $rowBg . '>
              <td style="white-space:nowrap;">' . htmlspecialchars($e->label) . ' ' . htmlspecialchars($e->cpt_code) . '</td>
              <td>' . htmlspecialchars($entity_name) . '</td>
              <td align="right">$' . number_format($e->amount, 2) . '<br>' . $htmlHour . '</td>
              <td>' . htmlspecialchars($e->notes);
        $html .= '</td></tr>';
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
    return $html;
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
   * AJAX endpoint to display the payroll summary PDF for all workers.
   * Requires GET parameter: payroll_id
   * Generates and outputs the PDF in the browser.
   */
  public static function ajax_payroll_summary_pdf()
  {
    self::check();
    $payroll_id = (int)($_GET['payroll_id'] ?? 0);
    if ($payroll_id <= 0) {
      status_header(400);
      echo 'Missing payroll_id';
      exit;
    }
    $pdfPath = self::generatePayrollSummaryPdf($payroll_id);
    if (!file_exists($pdfPath)) {
      status_header(500);
      echo 'Error generating PDF';
      exit;
    }
    $download_name = 'payroll_summary_' . $payroll_id . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $download_name . '"');
    readfile($pdfPath);
    unlink($pdfPath);
    exit;
  }

  /**
   * Generates the payroll summary PDF for all workers in a payroll.
   * @param int $payroll_id
   * @return string Path to the generated PDF file
   */
  public static function generatePayrollSummaryPdf($payroll_id)
  {
    global $wpdb;
    // Get payroll info
    $payroll = \Mhc\Inc\Models\Payroll::findById($payroll_id);
    $start = $payroll->start_date ?? date('Y-m-d');
    $end   = $payroll->end_date   ?? date('Y-m-d');
    $status = $payroll->status ?? '';
    // Get summary data (like ajax_workers)
    $hours = \Mhc\Inc\Models\HoursEntry::totalsByWorkerForPayroll($payroll_id);
    $extras = \Mhc\Inc\Models\ExtraPayment::totalsByWorkerForPayroll($payroll_id);
    $map = [];
    foreach ($hours as $h) {
      $wid = (int)$h['worker_id'];
      $map[$wid] = [
        'worker_id'     => $wid,
        'worker_name'   => $h['worker_name'] ?? '',
        'company'       => $h['worker_company'] ?? '',
        'check_number'  => $h['check_number'] ?? '---',
        'hours_hours'   => (float)$h['total_hours'],
        'hours_amount'  => (float)$h['total_amount'],
        'extras_amount' => 0.0,
      ];
    }
    foreach ($extras as $e) {
      $wid = (int)$e['worker_id'];
      if (!isset($map[$wid])) {
        // Try to get name/company from extras row if available
        $worker_name = $e['worker_name'] ?? '';
        $company = $e['worker_company'] ?? '';
        $check_number = $e['check_number'] ?? '---';
        // If not present, try to fetch from DB
        if ((!$worker_name || !$company) && $wid) {
          global $wpdb;
          $t = $wpdb->prefix . 'mhc_workers';
          $row = $wpdb->get_row($wpdb->prepare("SELECT CONCAT(first_name,' ',last_name) AS name, company FROM {$t} WHERE id=%d", $wid));
          if ($row) {
            $worker_name = (string)$row->name;
            $company = (string)$row->company;
          }
          
          $check_number = $wpdb->get_var($wpdb->prepare(
            "SELECT check_number FROM {$wpdb->prefix}mhc_qb_checks WHERE payroll_id = %d AND worker_id = %d",
            $payroll_id,
            $wid
          )) ?? '---';
        }
        $map[$wid] = [
          'worker_id'     => $wid,
          'worker_name'   => $worker_name,
          'company'       => $company,
          'check_number'  => $check_number,
          'hours_hours'   => 0.0,
          'hours_amount'  => 0.0,
          'extras_amount' => 0.0,
        ];
      }
      $map[$wid]['extras_amount'] = (float)$e['total_amount'];
    }
    $items = array_values(array_map(function ($r) {
      $r['grand_total'] = round((float)$r['hours_amount'] + (float)$r['extras_amount'], 2);
      return $r;
    }, $map));

    // Sort by worker name
    usort($items, function ($a, $b) {
      return strcmp($a['worker_name'], $b['worker_name']);
    });
    // Totals
    $sum_hours_amount  = array_sum(array_column($items, 'hours_amount'));
    $sum_extras_amount = array_sum(array_column($items, 'extras_amount'));
    $sum_grand_total   = $sum_hours_amount + $sum_extras_amount;
    // PDF HTML
    $logo_path = dirname(__DIR__, 2) . '/assets/img/mentalhelt.jpg';
    $html = '<style>
      body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color:#333; }
      .header { border-bottom: 2px solid #006699; padding-bottom: 10px; margin-bottom: 20px; }
      .header h2 { margin:0; color:#000; font-size: 16px; }
      .section-title { font-size: 13px; font-weight: bold; color:#006699; margin: 18px 0 8px; }
      table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
      thead th { background:#f2f2f2; color:#000; font-weight:bold; text-align:center; border:1px solid #bbb; }
      tbody td, tfoot td { border:1px solid #bbb; }
      th, td { font-size:10px; padding:8px; }
      .totals td { background: #e6f0fa; font-weight: bold; }
      .totals td:first-child { text-align:left; }
      .footer { font-size: 9px; color:#555; text-align:center; margin-top:22px; }
    </style>';
    $html .= '<div class="header">
      <table style="border:none; width:100%"><tr>
        <td style="width:70%; border:none;">' . (file_exists($logo_path) ? '<img src="' . $logo_path . '" width="100" />' : '') . '</td>
        <td style="width:30%; text-align:right; border:none;">
          <h2>Payroll Workers Summary</h2>
          <div style="font-size:10px; margin-top: 1rem;">Agency of Mental Health Services</div>
        </td>
      </tr></table>
      <div style="margin-top:8px; font-size:12px;">Period: <b>' . self::format_week_range($start, $end) . '</b> &nbsp;|&nbsp; Status: <b>' . htmlspecialchars($status) . '</b></div>
    </div>';
    $html .= '<div class="section-title">Workers Summary</div>';
    $html .= '<table><thead><tr>
      <th>Worker</th>
      <th>Company</th>
      <th>Hours</th>
      <th>Hours $</th>
      <th>Additionals $</th>
      <th>Check #</th>
      <th>Total $</th>
    </tr></thead><tbody>';
    if (!empty($items)) {
      foreach ($items as $i) {
        $html .= '<tr>
          <td>' . htmlspecialchars($i['worker_name']) . '</td>
          <td>' . htmlspecialchars($i['company']) . '</td>
          <td align="center">' . number_format($i['hours_hours'], 2) . '</td>
          <td align="right">$' . number_format($i['hours_amount'], 2) . '</td>
          <td align="right">$' . number_format($i['extras_amount'], 2) . '</td>
          <td align="right">' . htmlspecialchars($i['check_number']) . '</td>
          <td align="right"><b>$' . number_format($i['grand_total'], 2) . '</b></td>
        </tr>';
      }
    } else {
      $html .= '<tr><td colspan="6" align="center">No data</td></tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<div class="section-title">Payroll Totals</div>';
    $html .= '<table><tbody>';
    $html .= '<tr class="totals"><td>Regular</td><td colspan="5" align="right">$' . number_format($sum_hours_amount, 2) . '</td></tr>';
    $html .= '<tr class="totals"><td>Additionals</td><td colspan="5" align="right">$' . number_format($sum_extras_amount, 2) . '</td></tr>';
    $html .= '<tr class="totals"><td>Grand Total</td><td colspan="5" align="right"><b>$' . number_format($sum_grand_total, 2) . '</b></td></tr>';
    $html .= '</tbody></table>';
    $html .= '<div class="footer">Summary generated automatically - Agency of Mental Health Services © ' . date('Y') . '</div>';
    // mPDF
    $mpdf = new Mpdf([
      'mode' => 'utf-8',
      'format' => 'A4',
      'margin_left'   => 10,
      'margin_right'  => 10,
      'margin_top'    => 20,
      'margin_bottom' => 15,
      'tempDir' => WP_CONTENT_DIR . '/uploads/mpdf',
    ]);
    $mpdf->SetTitle('Payroll Workers Summary PDF');
    $mpdf->SetAuthor('MHC');
    $mpdf->SetCreator('MHC Payroll');
    $mpdf->WriteHTML($html);
    $tmp = tempnam(sys_get_temp_dir(), 'payrollsummary_') . '.pdf';
    $mpdf->Output($tmp, Destination::FILE);
    return $tmp;
  }

  public static function ajax_all_slips_pdf()
  {
    self::check();
    global $wpdb;

    $payrollId = intval($_GET['payroll_id'] ?? 0);
    if (!$payrollId) {
      wp_send_json_error(['message' => 'Missing payroll_id']);
    }


    // Buscar todos los trabajadores con horas o extras en este payroll
    $workers = [];
    $worker_ids = [];
    // 1. Workers with hours
    $res1 = $wpdb->get_results(
      $wpdb->prepare("
        SELECT DISTINCT w.id, CONCAT(w.first_name, ' ', w.last_name) AS worker_name, w.company
        FROM {$wpdb->prefix}mhc_hours_entries he
        INNER JOIN {$wpdb->prefix}mhc_worker_patient_roles wpr ON wpr.id = he.worker_patient_role_id
        INNER JOIN {$wpdb->prefix}mhc_workers w ON w.id = wpr.worker_id
        INNER JOIN {$wpdb->prefix}mhc_payroll_segments seg ON seg.id = he.segment_id
        WHERE seg.payroll_id = %d
      ", $payrollId)
    );
    foreach ($res1 as $w) {
      $workers[] = $w;
      $worker_ids[$w->id] = true;
    }
    // 2. Workers with only extras
    $res2 = $wpdb->get_results(
      $wpdb->prepare("
        SELECT DISTINCT w.id, CONCAT(w.first_name, ' ', w.last_name) AS worker_name, w.company, qc.check_number AS check_number
        FROM {$wpdb->prefix}mhc_extra_payments ep
        INNER JOIN {$wpdb->prefix}mhc_workers w ON w.id = ep.worker_id
        LEFT JOIN {$wpdb->prefix}mhc_qb_checks qc ON qc.payroll_id = ep.payroll_id AND qc.worker_id = ep.worker_id
        WHERE ep.payroll_id = %d
      ", $payrollId)
    );
    foreach ($res2 as $w) {
      if (!isset($worker_ids[$w->id])) {
        $workers[] = $w;
        $worker_ids[$w->id] = true;
      }
    }
    if (!$workers) {
      wp_send_json_error(['message' => 'No workers found for this payroll']);
    }

    //ORDER workers by name
    usort($workers, function ($a, $b) {
      return strcmp($a->worker_name, $b->worker_name);
    });

    try {
      $mpdf = new \Mpdf\Mpdf();

      foreach ($workers as $i => $worker) {
        // Generar los datos igual que en generateWorkerSlipPdf
        $hours  = \Mhc\Inc\Models\HoursEntry::listDetailedForPayroll($payrollId, ['worker_id' => $worker->id]);
        $extras = \Mhc\Inc\Models\ExtraPayment::listDetailedForPayroll($payrollId, ['worker_id' => $worker->id]);
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
        // Prefer company from hours, else from worker row
        $company_name = '';
        $check_number = '---';
        if (!empty($hours)) {
          $company_name = $hours[0]->worker_company ?? '';
          $check_number = $hours[0]->check_number ?? '---';
        } elseif (!empty($worker->company)) {
          $company_name = $worker->company;
          $check_number = $worker->check_number ?? '---';
        }
        $data = [
          'payroll_id' => $payrollId,
          'worker_id' => $worker->id,
          'hours' => $hours,
          'extras' => $extras,
          'totals' => [
            'total_hours'   => round($th, 2),
            'hours_amount'  => round($ta, 2),
            'extras_amount' => round($te, 2),
            'grand_total'   => round($ta + $te, 2),
          ],
        ];
        $payroll = \Mhc\Inc\Models\Payroll::findById($payrollId);
        $start = $payroll->start_date ?? date('Y-m-d');
        $end   = $payroll->end_date   ?? date('Y-m-d');
        $html = self::renderWorkerSlipHtml($data, $worker->worker_name, $company_name, $start, $end, $check_number);
        if ($i > 0) {
          $mpdf->AddPage();
        }
        $mpdf->WriteHTML($html);
      }

      $filename = "Payroll_{$payrollId}_AllSlips.pdf";
      $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
      exit;
    } catch (\Exception $e) {
      wp_send_json_error(['message' => $e->getMessage()]);
    }
  }
}
