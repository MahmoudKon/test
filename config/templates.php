<?php

$range   = 170;
$item_id = 770;
$qty     = 1;

return [
    "Standard_Invoice.xml" => [
        "TRANSACTION_TYPE" => INVOICE_SALES,
        "SET_COUNTER" => ($range + 1),
        "SET_NUMBER" => ($range + 1),
        "SET_UUID" => \Ramsey\Uuid\Uuid::uuid4()->toString(),
        "SET_DOC_TYPE" => "standard",
        "SET_INVOICE_TYPE" => "388",
        "SET_DATE" => date('Y-m-d'),
        "SET_TIME" => date('H:i:s'),
        "SET_INVOICE_NUMBER" => null,
        "SET_INVOICE_ID" => "id",

        "ITEM_ID" => "I-" . ($item_id + 1),
        "ITEM_QTY" => (string) $qty,
        "ITEM_SELL_PRICE" => "100",
        "ITEM_NAME" => "Example Item",
        "ITEM_VAT" => 15,
        "ITEM_CATEGORY" => "S",

        "CLIENT_TRN" => "311111121111113",
        "CLIENT_STREET" => "King Street",
        "CLIENT_NAME" => "John Doe"
    ],
    "Standard_Debit_Note.xml" => [
        "TRANSACTION_TYPE" => INVOICE_SALE_RETURNS,
        "SET_COUNTER" => ($range + 2),
        "SET_NUMBER" => ($range + 2),
        "SET_UUID" => \Ramsey\Uuid\Uuid::uuid4()->toString(),
        "SET_DOC_TYPE" => "standard",
        "SET_INVOICE_TYPE" => "383",
        "SET_DATE" => date('Y-m-d'),
        "SET_TIME" => date('H:i:s'),
        "SET_INVOICE_NUMBER" => ($range + 1),
        "SET_INVOICE_ID" => "id",

        "ITEM_ID" => "I-" . ($item_id + 2),
        "ITEM_QTY" => (string) $qty,
        "ITEM_SELL_PRICE" => "115",
        "ITEM_NAME" => "Example Item",
        "ITEM_VAT" => 15,
        "ITEM_CATEGORY" => "S",

        "CLIENT_TRN" => "311111121111113",
        "CLIENT_STREET" => "King Street",
        "CLIENT_NAME" => "John Doe"
    ],
    "Standard_Credit_Note.xml" => [
        "TRANSACTION_TYPE" => INVOICE_SALES,
        "SET_COUNTER" => ($range + 3),
        "SET_NUMBER" => ($range + 3),
        "SET_UUID" => \Ramsey\Uuid\Uuid::uuid4()->toString(),
        "SET_DOC_TYPE" => "standard",
        "SET_INVOICE_TYPE" => "381",
        "SET_DATE" => date('Y-m-d'),
        "SET_TIME" => date('H:i:s'),
        "SET_INVOICE_NUMBER" => ($range + 1),
        "SET_INVOICE_ID" => "id",

        "ITEM_ID" => "I-" . ($item_id + 3),
        "ITEM_QTY" => (string) $qty,
        "ITEM_SELL_PRICE" => "115",
        "ITEM_NAME" => "Example Item",
        "ITEM_VAT" => 15,
        "ITEM_CATEGORY" => "S",

        "CLIENT_TRN" => "311111121111113",
        "CLIENT_STREET" => "King Street",
        "CLIENT_NAME" => "John Doe"
    ],
    "Simplified_Invoice.xml" => [
        "TRANSACTION_TYPE" => INVOICE_SALES,
        "SET_COUNTER" => ($range + 4),
        "SET_NUMBER" => ($range + 4),
        "SET_UUID" => \Ramsey\Uuid\Uuid::uuid4()->toString(),
        "SET_DOC_TYPE" => "simplified",
        "SET_INVOICE_TYPE" => "388",
        "SET_DATE" => date('Y-m-d'),
        "SET_TIME" => date('H:i:s'),
        "SET_INVOICE_NUMBER" => null,
        "SET_INVOICE_ID" => "id",

        "ITEM_ID" => "I-" . ($item_id + 4),
        "ITEM_QTY" => (string) $qty,
        "ITEM_SELL_PRICE" => "115",
        "ITEM_NAME" => "Example Item",
        "ITEM_VAT" => 15,
        "ITEM_CATEGORY" => "S",

        "CLIENT_TRN" => "",
        "CLIENT_STREET" => "King Street",
        "CLIENT_NAME" => "Alex Player"
    ],
    "Simplified_Debit_Note.xml" => [
        "TRANSACTION_TYPE" => INVOICE_SALES,
        "SET_COUNTER" => ($range + 5),
        "SET_NUMBER" => ($range + 5),
        "SET_UUID" => \Ramsey\Uuid\Uuid::uuid4()->toString(),
        "SET_DOC_TYPE" => "simplified",
        "SET_INVOICE_TYPE" => "381",
        "SET_DATE" => date('Y-m-d'),
        "SET_TIME" => date('H:i:s'),
        "SET_INVOICE_NUMBER" => ($range + 4),
        "SET_INVOICE_ID" => "id",

        "ITEM_ID" => "I-" . ($item_id + 5),
        "ITEM_QTY" => (string) $qty,
        "ITEM_SELL_PRICE" => "115",
        "ITEM_NAME" => "Example Item",
        "ITEM_VAT" => 15,
        "ITEM_CATEGORY" => "S",

        "CLIENT_TRN" => "",
        "CLIENT_STREET" => "King Street",
        "CLIENT_NAME" => "Alex Player"
    ],
    "Simplified_Credit_Note.xml" => [
        "TRANSACTION_TYPE" => INVOICE_SALE_RETURNS,
        "SET_COUNTER" => ($range + 6),
        "SET_NUMBER" => ($range + 6),
        "SET_UUID" => \Ramsey\Uuid\Uuid::uuid4()->toString(),
        "SET_DOC_TYPE" => "simplified",
        "SET_INVOICE_TYPE" => "383",
        "SET_DATE" => date('Y-m-d'),
        "SET_TIME" => date('H:i:s'),
        "SET_INVOICE_NUMBER" => ($range + 4),
        "SET_INVOICE_ID" => "id",

        "ITEM_ID" => "I-" . ($item_id + 6),
        "ITEM_QTY" => (string) $qty,
        "ITEM_SELL_PRICE" => "115",
        "ITEM_NAME" => "Example Item",
        "ITEM_VAT" => 15,
        "ITEM_CATEGORY" => "S",

        "CLIENT_TRN" => "",
        "CLIENT_STREET" => "King Street",
        "CLIENT_NAME" => "Alex Player"
    ],
];
