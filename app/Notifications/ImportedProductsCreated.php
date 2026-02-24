<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ImportedProductsCreated extends Notification
{
    use Queueable;

    protected array $products;
    protected string $userFullName;
    protected string $userEmail;
    protected string $companyName;

    public function __construct(
        array $products,
        string $userFullName,
        string $userEmail,
        string $companyName
    ) {
        $this->products = $products;
        $this->userFullName = $userFullName;
        $this->userEmail = $userEmail;
        $this->companyName = $companyName;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $productsCount = count($this->products);
        $productsTableHtml = '';

        if ($productsCount > 0) {
            $rows = '';
            foreach ($this->products as $product) {
                if (is_object($product)) {
                    $product->loadMissing(['brand', 'category', 'equipments']);
                }
                $model = $product->model ?? 'N/A';
                $brandName = $product->brand->name ?? 'N/A';
                $categoryName = $product->category->name ?? 'N/A';
                $psmCode = $product->psm_code ?? 'N/A';
                $softwareCode = $product->software_code ?? 'N/A';
                $rows .= '<tr><td>' . e($model) . '</td><td>' . e($brandName) . '</td><td>' . e($categoryName) . '</td><td>' . e($psmCode) . '</td><td>' . e($softwareCode) . '</td></tr>';
            }
            $productsTableHtml = '<h3 style="margin-top: 25px; color: #1a73e8;">Imported Products (' . $productsCount . ')</h3><table width="100%" cellpadding="8" cellspacing="0" style="border: 1px solid #ccc; border-radius: 6px; margin-top: 10px;"><tr style="background-color: #e8eef8;"><th align="left">Model</th><th align="left">Brand</th><th align="left">Category</th><th align="left">PSM Code</th><th align="left">Rental Software Code</th></tr>' . $rows . '</table>';
        }

        return (new MailMessage)
            ->subject('Product Import Completed - New Items Added')
            ->view('emails.imported_products', [
                'user_full_name'     => $this->userFullName,
                'user_email'         => $this->userEmail,
                'company_name'       => $this->companyName,
                'products_count'     => $productsCount,
                'products_table_html'=> $productsTableHtml,
                'current_year'       => (string) date('Y'),
            ]);
    }
}
