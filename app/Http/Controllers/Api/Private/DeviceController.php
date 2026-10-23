<?php

namespace App\Http\Controllers\Api\Private;

use App\Http\Requests\Device\UpdateDeviceRequest;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeviceController
{
    public function update(UpdateDeviceRequest $request, Device $device)
    {
        $device = $request->user()->devices()->whereKey($device->getKey())->firstOrFail();
        $device->update($request->validated());

        return response()->json([
            'name' => $device->name,
        ]);
    }

    public function delete(Request $request, Device $device): Response
    {
        $device = $request->user()->devices()->whereKey($device->getKey())->firstOrFail();
        $device->delete();

        return response()->noContent();
    }

}
