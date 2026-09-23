<?php

namespace App\Http\Controllers;

use App\Models\GisMapState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GisMapStateController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('workspace.view'), 403);

        return response()->json(['state' => GisMapState::query()->where('user_id', $request->user()->id)->value('state') ?? []]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('workspace.view'), 403);
        $data = $request->validate(['state' => ['required', 'array:layers,center,zoom'], 'state.layers' => ['present', 'array', 'max:500'], 'state.layers.*' => ['array:id,visible,opacity,order'], 'state.layers.*.id' => ['required', 'ulid'], 'state.layers.*.visible' => ['required', 'boolean'], 'state.layers.*.opacity' => ['required', 'numeric', 'between:0,1'], 'state.layers.*.order' => ['required', 'integer', 'between:0,500'], 'state.center' => ['nullable', 'array', 'size:2'], 'state.center.*' => ['numeric', 'between:-180,180'], 'state.zoom' => ['nullable', 'numeric', 'between:0,22']]);
        GisMapState::query()->updateOrCreate(['user_id' => $request->user()->id], ['state' => $data['state']]);

        return response()->json(['saved' => true]);
    }
}
