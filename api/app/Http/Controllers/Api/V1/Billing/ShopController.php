<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Billing\PackageCatalogue;
use App\Billing\PriceCalculator;
use App\Http\Controllers\Controller;
use App\Models\BillingSetting;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Provider shop: packages on sale and price quotes. */
final class ShopController extends Controller
{
    public function __construct(
        private readonly PackageCatalogue $catalogue,
        private readonly PriceCalculator $prices,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogue->onSale()->map(fn (Package $p) => $this->catalogue->present($p))->values()->all(),
            'vat_rate' => BillingSetting::current()->vatRate(),
        ]);
    }

    public function quote(Request $request, Package $package): JsonResponse
    {
        abort_unless($package->status === Package::PUBLISHED, 404);
        $data = $request->validate(['seats' => ['required', 'integer', 'min:1', 'max:100000']]);

        return response()->json(['data' => $this->prices->quote($package, (int) $data['seats'])]);
    }
}
