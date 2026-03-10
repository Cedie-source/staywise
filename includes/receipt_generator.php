<?php
/**
 * StayWise PDF Receipt Generator
 * 
 * Uses FPDF to create a professional payment receipt.
 * Returns the file path of the generated PDF.
 */

require_once __DIR__ . '/../vendor/fpdf/fpdf.php';

class ReceiptGenerator extends FPDF {

    /**
     * Generate a PDF receipt and save it to uploads/receipts/
     *
     * @param array $data [
     *   'payment_id'     => int,
     *   'tenant_name'    => string,
     *   'tenant_email'   => string,
     *   'unit_number'    => string,
     *   'amount'         => float (pesos),
     *   'for_month'      => string (e.g. '2026-03'),
     *   'payment_method' => string,
     *   'transaction_id' => string,
     *   'paid_at'        => string (datetime),
     *   'reference_no'   => string (optional),
     * ]
     * @return string Relative file path from project root (e.g. 'uploads/receipts/receipt_123_xxx.pdf')
     */
    public static function generate(array $data): string {
        $receiptsDir = __DIR__ . '/../uploads/receipts';
        if (!is_dir($receiptsDir)) {
            mkdir($receiptsDir, 0755, true);
        }

        $fileName = 'receipt_' . ($data['payment_id'] ?? 0) . '_' . time() . '.pdf';
        $filePath = $receiptsDir . '/' . $fileName;

        $pdf = new self('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();
        $pdf->SetMargins(20, 20, 20);

        // ── Header ─────────────────────────────────────────
        $pdf->SetFont('Helvetica', 'B', 24);
        $pdf->SetTextColor(13, 110, 253); // Bootstrap primary blue
        $pdf->Cell(0, 12, 'StayWise', 0, 1, 'C');
        
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(108, 117, 125);
        $pdf->Cell(0, 6, 'AI-Enabled Rental Management Platform', 0, 1, 'C');
        
        $pdf->Ln(4);
        $pdf->SetDrawColor(222, 226, 230);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(8);

        // ── Title ──────────────────────────────────────────
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(0, 10, 'PAYMENT RECEIPT', 0, 1, 'C');
        $pdf->Ln(4);

        // Receipt number
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(100, 116, 139);
        $receiptNo = 'SW-' . str_pad($data['payment_id'] ?? '0', 6, '0', STR_PAD_LEFT);
        $pdf->Cell(0, 6, 'Receipt No: ' . $receiptNo, 0, 1, 'C');
        $pdf->Ln(8);

        // ── Details Table ──────────────────────────────────
        $pdf->SetFillColor(248, 249, 250);
        $labelX = 25;
        $valueX = 85;
        $rowH = 10;

        $monthLabel = '—';
        if (!empty($data['for_month'])) {
            $ts = strtotime($data['for_month'] . '-01');
            if ($ts) $monthLabel = date('F Y', $ts);
        }

        $paidAt = $data['paid_at'] ?? date('Y-m-d H:i:s');
        $paidFormatted = date('F j, Y – g:i A', strtotime($paidAt));

        $methodNames = [
            'paymongo_gcash' => 'GCash (PayMongo)',
            'gcash'          => 'GCash (PayMongo)',
            'manual_gcash'   => 'GCash (Manual)',
            'cash'           => 'Cash',
        ];
        $methodDisplay = $methodNames[$data['payment_method'] ?? ''] ?? ucfirst($data['payment_method'] ?? 'GCash');

        $details = [
            ['Tenant Name',    $data['tenant_name'] ?? '—'],
            ['Email',          $data['tenant_email'] ?? '—'],
            ['Unit Number',    $data['unit_number'] ?? '—'],
            ['Month Covered',  $monthLabel],
            ['Amount Paid',    iconv('UTF-8', 'ISO-8859-1//IGNORE', '₱') !== false ? 'PHP ' . number_format((float)($data['amount'] ?? 0), 2) : 'PHP ' . number_format((float)($data['amount'] ?? 0), 2)],
            ['Payment Method', $methodDisplay],
            ['Transaction ID', $data['transaction_id'] ?? '—'],
            ['Date Paid',      $paidFormatted],
        ];

        if (!empty($data['reference_no'])) {
            $details[] = ['Reference No.', $data['reference_no']];
        }

        $fill = false;
        foreach ($details as $row) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->SetTextColor(71, 85, 105);
            $pdf->SetX($labelX);
            $pdf->Cell(60, $rowH, $row[0], 0, 0, 'L', $fill);

            $pdf->SetFont('Helvetica', '', 10);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->Cell(0, $rowH, $row[1], 0, 1, 'L', $fill);
            $fill = !$fill;
        }

        // ── Amount highlight ───────────────────────────────
        $pdf->Ln(8);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetTextColor(25, 135, 84); // green
        $amountStr = 'PHP ' . number_format((float)($data['amount'] ?? 0), 2);
        $pdf->Cell(0, 12, $amountStr, 0, 1, 'C');

        // ── PAID Stamp ─────────────────────────────────────
        $pdf->Ln(6);
        $pdf->SetFont('Helvetica', 'B', 36);
        $pdf->SetTextColor(25, 135, 84);
        // Draw a rounded rect "stamp"
        $stampW = 60;
        $stampH = 18;
        $stampX = ($pdf->GetPageWidth() - $stampW) / 2;
        $stampY = $pdf->GetY();
        $pdf->SetDrawColor(25, 135, 84);
        $pdf->SetLineWidth(1.5);
        $pdf->Rect($stampX, $stampY, $stampW, $stampH);
        $pdf->SetXY($stampX, $stampY + 1);
        $pdf->Cell($stampW, $stampH - 2, 'PAID', 0, 1, 'C');
        $pdf->SetLineWidth(0.2);

        // ── Footer ─────────────────────────────────────────
        $pdf->SetY(-40);
        $pdf->SetDrawColor(222, 226, 230);
        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(4);

        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetTextColor(148, 163, 184);
        $pdf->Cell(0, 5, 'This is a system-generated receipt. No signature required.', 0, 1, 'C');
        $pdf->Cell(0, 5, 'Generated on ' . date('F j, Y'), 0, 1, 'C');

        // Save
        $pdf->Output('F', $filePath);

        return 'uploads/receipts/' . $fileName;
    }
}
