<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Address\StoreAddressRequest;
use App\Http\Requests\Address\UpdateAddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    public function index(Request $request)
    {
        return AddressResource::collection(
            $request->user()->addresses()->orderByDesc('is_default')->orderByDesc('id')->get()
        );
    }

    public function store(StoreAddressRequest $request)
    {
        $data = $request->validated();

        $address = DB::transaction(function () use ($request, $data): Address {
            $address = $request->user()->addresses()->create($data);
            $this->syncDefault($request, $address);

            return $address;
        });

        return (new AddressResource($address->refresh()))->response()->setStatusCode(201);
    }

    public function show(Request $request, Address $address)
    {
        $this->authorizeAddress($request, $address);

        return new AddressResource($address);
    }

    public function update(UpdateAddressRequest $request, Address $address)
    {
        $this->authorizeAddress($request, $address);

        $data = $request->validated();

        DB::transaction(function () use ($request, $address, $data): void {
            $address->update($data);
            $this->syncDefault($request, $address);
        });

        return new AddressResource($address->refresh());
    }

    public function destroy(Request $request, Address $address)
    {
        $this->authorizeAddress($request, $address);

        $address->delete();

        return response()->json(['message' => 'Address removed.']);
    }

    private function authorizeAddress(Request $request, Address $address): void
    {
        abort_unless($address->user_id === $request->user()->id, 403, 'This address is not yours.');
    }

    private function syncDefault(Request $request, Address $address): void
    {
        if (! $address->is_default) {
            return;
        }

        $request->user()->addresses()
            ->whereKeyNot($address->id)
            ->update(['is_default' => false]);
    }
}
