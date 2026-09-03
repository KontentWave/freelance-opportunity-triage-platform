<?php

namespace App\Domain\Opportunities\Enums;

enum RedirectResolutionErrorCode: string
{
    case UrlRejected = 'redirect_url_rejected';
    case AddressRejected = 'redirect_address_rejected';
    case Timeout = 'redirect_timeout';
    case ResponseInvalid = 'redirect_response_invalid';
    case LimitExceeded = 'redirect_limit_exceeded';
    case DestinationInvalid = 'redirect_destination_invalid';
}
