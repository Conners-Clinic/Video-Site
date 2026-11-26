<?php
// This API accepted no verifiable payment proof and must not mint paid access or ledger credit.
http_response_code(403);
$response_data = array('api_status' => '403', 'api_version' => $api_version,
    'errors' => array('error_id' => 'payment_verification_required',
        'error_text' => 'Complete payment through the verified website checkout.'));
return;
