<?php

namespace App\Http\Requests\Admin;

class UpdateProductRequest extends StoreProductRequest
{
    // Same validation surface as creating a product; the unique slug rule in
    // the parent already ignores the current product.
}
