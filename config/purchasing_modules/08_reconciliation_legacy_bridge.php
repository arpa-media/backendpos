<?php
return ['modules'=>[[
 'key'=>'reconciliation','code'=>'purchasing-reconciliation','label'=>'Reconciliation','singular_label'=>'Reconciliation',
 'description'=>'Pemetaan dan audit dokumen legacy Stock Inventory, Purchasing, dan Warehouse ke canonical workflow.',
 'path'=>'/purchasing/reconciliation','route_name'=>'purchasing-reconciliation','sort_order'=>130,
 'permission'=>'purchasing.reconciliation','document_prefix'=>'RECON','implementation_status'=>'WORKFLOW',
 'status_options'=>['LINKED','WARNING','CONFLICT','UNRESOLVED'],
]]];
