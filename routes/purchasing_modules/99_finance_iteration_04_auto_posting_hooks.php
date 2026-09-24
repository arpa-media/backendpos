<?php
use App\Http\Controllers\Api\V1\Purchasing\PurchasingInvoiceFinanceAutoPostController as C;
use Illuminate\Support\Facades\Route;
foreach(['incoming'=>'purchasing.incoming_invoice','outgoing'=>'purchasing.outgoing_invoice'] as $direction=>$permission){
 Route::prefix('api/v1/purchasing/invoices/'.$direction)->middleware(['api','auth:sanctum'])->group(function()use($direction,$permission):void{
   Route::post('/{id}/issue',[C::class,'issue'])->defaults('direction',$direction)->middleware("permission_or_snapshot:{$permission}.update,{$permission}.issue")->name("purchasing.invoice.{$direction}.issue");
   Route::post('/{id}/payments',[C::class,'payment'])->defaults('direction',$direction)->middleware("permission_or_snapshot:{$permission}.create,{$permission}.payment")->name("purchasing.invoice.{$direction}.payment");
 });
}
