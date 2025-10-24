<?php
declare(strict_types=1);

namespace App;

use Dompdf\Dompdf;
use Dompdf\Options;

class PDFGenerator
{
    public static function generateTicketPDF(array $ticket, array $trip, array $company, array $user): string
    {
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Bilet - ' . htmlspecialchars($company['name']) . '</title>
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    margin: 0; 
                    padding: 20px; 
                    background: #f5f5f5;
                }
                .ticket { 
                    background: white;
                    border: 2px solid #333; 
                    padding: 30px; 
                    max-width: 500px; 
                    margin: 0 auto;
                    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
                }
                .header { 
                    text-align: center; 
                    font-size: 24px; 
                    font-weight: bold; 
                    margin-bottom: 30px; 
                    color: #2c3e50;
                    border-bottom: 2px solid #3498db;
                    padding-bottom: 15px;
                }
                .info { 
                    margin: 15px 0; 
                    padding: 8px 0;
                    border-bottom: 1px solid #ecf0f1;
                }
                .label { 
                    font-weight: bold; 
                    color: #34495e;
                    display: inline-block;
                    width: 120px;
                }
                .value {
                    color: #2c3e50;
                }
                .footer { 
                    text-align: center; 
                    margin-top: 30px; 
                    font-size: 14px; 
                    color: #7f8c8d;
                    font-style: italic;
                }
                .qr-placeholder {
                    width: 100px;
                    height: 100px;
                    border: 2px dashed #bdc3c7;
                    display: inline-block;
                    text-align: center;
                    line-height: 100px;
                    color: #95a5a6;
                    font-size: 12px;
                    margin: 20px 0;
                }
            </style>
        </head>
        <body>
            <div class="ticket">
                <div class="header">' . htmlspecialchars($company['name']) . '</div>
                <div class="info">
                    <span class="label">Bilet No:</span> 
                    <span class="value">' . (int)$ticket['id'] . '</span>
                </div>
                <div class="info">
                    <span class="label">PNR:</span> 
                    <span class="value">' . htmlspecialchars($ticket['pnr'] ?? 'N/A') . '</span>
                </div>
                <div class="info">
                    <span class="label">Yolcu:</span> 
                    <span class="value">' . htmlspecialchars($user['email']) . '</span>
                </div>
                <div class="info">
                    <span class="label">Kalkış:</span> 
                    <span class="value">' . htmlspecialchars($trip['origin']) . '</span>
                </div>
                <div class="info">
                    <span class="label">Varış:</span> 
                    <span class="value">' . htmlspecialchars($trip['destination']) . '</span>
                </div>
                <div class="info">
                    <span class="label">Tarih/Saat:</span> 
                    <span class="value">' . htmlspecialchars((new \DateTimeImmutable($trip['departure_at']))->format('d.m.Y H:i')) . '</span>
                </div>
                <div class="info">
                    <span class="label">Koltuk:</span> 
                    <span class="value">' . (int)$ticket['seat_number'] . '</span>
                </div>
                <div class="info">
                    <span class="label">Fiyat:</span> 
                    <span class="value">' . number_format((int)$ticket['price_paid_cents'] / 100, 2, ',', '.') . ' TL</span>
                </div>
                <div class="info">
                    <span class="label">Satın Alma:</span> 
                    <span class="value">' . htmlspecialchars((new \DateTimeImmutable($ticket['purchased_at']))->format('d.m.Y H:i')) . '</span>
                </div>
                <div class="info">
                    <span class="label">Durum:</span> 
                    <span class="value">' . htmlspecialchars($ticket['status']) . '</span>
                </div>
                <div style="text-align: center; margin: 20px 0;">
                    <div class="qr-placeholder">QR Kod</div>
                </div>
                <div class="footer">İyi yolculuklar dileriz!</div>
            </div>
        </body>
        </html>';

        return $html;
    }

    public static function generatePDFFromHTML(string $html): string
    {
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        return $dompdf->output();
    }
}
