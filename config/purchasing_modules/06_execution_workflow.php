<?php
return ['modules'=>[[
 'key'=>'realization-orders','code'=>'purchasing-realization-orders','label'=>'Realization Order','singular_label'=>'Realization',
 'path'=>'/purchasing/realization-orders','route_name'=>'purchasing-realization-orders','sort_order'=>40,'permission'=>'purchasing.realization_order','document_prefix'=>'REAL','implementation_status'=>'WORKFLOW',
 'status_options'=>['DRAFT','AWAITING_APPROVAL','APPROVED','CANCELLED'],
]]];
