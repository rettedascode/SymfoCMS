<?php

namespace App\Enum;

enum MenuItemType: string
{
    case Page = 'page';
    case Post = 'post';
    case Url = 'url';
}
