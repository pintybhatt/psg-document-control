<?php

return [
    'max_upload_kb' => (int) env('DCMS_MAX_UPLOAD_KB', 25600),
    'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'dwg', 'dxf'],
];
