<?php

namespace App\Services\Zatca\CSR;

class CSRValidationException extends \Exception
{

    public function __construct(string $message, int $code)
    {
        parent::__construct('The given data was invalid::' . $message, $code);
    }

}
