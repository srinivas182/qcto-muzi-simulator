<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Billing\PackageCatalogue;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** HQ price list: draft, price, publish, retire and duplicate packages. */
final class PackageController extends Controller
{
    public function __construct(private readonly PackageCatalogue $catalogue) {}

    public function index(): JsonResponse
    {
        $packages = Package::query()->with(['prices', 'programme'])->orderByRaw("CASE status WHEN 'draft' THEN 0 WHEN 'published' THEN 1 ELSE 2 END")->orderBy('name')->get();

        return response()->json(['data' => $packages->map(fn (Package $p) => $this->catalogue->present($p))->all()]);
    }

    public function show(Package $package): JsonResponse
    {
        return $this->respond($package);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        return $this->respond($this->catalogue->create($data, $this->actor($request)), 201);
    }

    public function update(Request $request, Package $package): JsonResponse
    {
        $data = $request->validate($this->rules(false));

        return $this->respond($this->catalogue->update($package, $data, $this->actor($request)));
    }

    public function prices(Request $request, Package $package): JsonResponse
    {
        /** @var array{prices: list<array{from_seats: int, unit_price_cents: int}>} $data */
        $data = $request->validate([
            'prices' => ['required', 'array', 'min:1', 'max:10'],
            'prices.*.from_seats' => ['required', 'integer', 'min:1', 'max:100000'],
            'prices.*.unit_price_cents' => ['required', 'integer', 'min:100', 'max:100000000'],
        ]);

        return $this->respond($this->catalogue->setPrices($package, $data['prices'], $this->actor($request)));
    }

    public function publish(Request $request, Package $package): JsonResponse
    {
        return $this->respond($this->catalogue->publish($package, $this->actor($request)));
    }

    public function retire(Request $request, Package $package): JsonResponse
    {
        return $this->respond($this->catalogue->retire($package, $this->actor($request)));
    }

    public function duplicate(Request $request, Package $package): JsonResponse
    {
        return $this->respond($this->catalogue->duplicate($package, $this->actor($request)), 201);
    }

    public function destroy(Request $request, Package $package): JsonResponse
    {
        $this->catalogue->discard($package, $this->actor($request));

        return response()->json(null, 204);
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'min:3', 'max:120'],
            'code' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'programme' => [$req, 'string', Rule::exists('programmes', 'code')],
            'includes_simulators' => [$req, 'boolean'],
            'includes_courses' => [$req, 'boolean'],
            'billing' => [$req, Rule::in([Package::TERM, Package::MONTHLY])],
            'term_months' => ['nullable', 'required_if:billing,term', 'integer', 'min:1', 'max:60'],
            'min_seats' => [$req, 'integer', 'min:1', 'max:10000'],
        ];
    }

    private function respond(Package $package, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->catalogue->present($package->refresh())], $status);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
