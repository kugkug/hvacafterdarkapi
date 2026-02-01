<?php

use App\Helpers\LogHelper;
use App\Helpers\ValidatorHelper;


function validatorHelper() {
    return new ValidatorHelper();
}

function logHelper() {
    return new LogHelper();
}