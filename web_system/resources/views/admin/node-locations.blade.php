@extends('layouts.app')

@section('title', 'Node locations')
@section('page_label', 'LoRa network')
@section('page_heading', 'Node locations')
@section('page_subheading', 'Set the installed position of each LoRa node.')

@section('content')
    <section class="panel node-locations-panel">
        <div class="panel-head">
            <div class="panel-heading">
                <h2 class="panel-title">Installed locations</h2>
                <p class="section-copy">{{ $loraNodes->filter(fn ($node) => $node['latitude'] !== null && $node['longitude'] !== null)->count() }} of {{ $loraNodes->count() }} nodes mapped. Open a node to enter its coordinates.</p>
            </div>
            <div class="node-location-actions">
                <a class="btn btn-primary" href="{{ route('admin.dashboard') }}#command-map">View Command map</a>
                <a class="btn btn-secondary" href="{{ route('monitoring.lora-mesh') }}">LoRa Mesh</a>
            </div>
        </div>
        <details class="node-location-help">
            <summary>How to enter coordinates</summary>
            <ol>
                <li>Choose the actual installation point in your map app and copy its decimal coordinates.</li>
                <li>Open the matching Node ID below. Enter a clear location name, then latitude first and longitude second.</li>
                <li>Click <strong>Save location</strong>, then <strong>View Command map</strong> to check the pin.</li>
            </ol>
            <p>Node pins stay visible when a node is offline. Location changes apply to new reports; existing reports keep their recorded location. Clear both coordinates to remove a node pin.</p>
        </details>
        <div class="node-location-list">
            @foreach ($loraNodes as $node)
                @php
                    $nodeId = $node['sender_id'];
                    $isEditing = old('_node_id') === $nodeId;
                    $isOpen = $isEditing || session('saved_node') === $nodeId;
                    $hasCoordinates = $node['latitude'] !== null && $node['longitude'] !== null;
                    $defaultLabel = str_starts_with($node['location_label'], 'Set the ') ? '' : $node['location_label'];
                @endphp
                <details class="node-location-card" name="node-locations" id="location-{{ $nodeId }}" @if ($isOpen) open @endif>
                    <summary>
                        <span><strong>{{ $nodeId }}</strong><small>{{ $node['node_name'] }}</small></span>
                        <span class="node-location-current">{{ $hasCoordinates ? $node['location_label'] : 'Coordinates not set' }}</span>
                        <span class="node-location-coordinate">{{ $hasCoordinates ? number_format($node['latitude'], 6).', '.number_format($node['longitude'], 6) : 'Add location' }}</span>
                        <span class="node-location-chevron" aria-hidden="true"></span>
                    </summary>
                    <form method="POST" action="{{ route('admin.node-locations.update', $nodeId) }}" class="node-location-form">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_node_id" value="{{ $nodeId }}">
                        <div class="field node-location-name">
                            <label for="label-{{ $nodeId }}">Location name</label>
                            <input class="input" id="label-{{ $nodeId }}" name="location_label" value="{{ $isEditing ? old('location_label') : $defaultLabel }}" maxlength="120" placeholder="Barangay, landmark or installation site" required>
                        </div>
                        <div class="field">
                            <label for="latitude-{{ $nodeId }}">Latitude</label>
                            <input class="input" id="latitude-{{ $nodeId }}" name="latitude" type="number" step="any" min="-90" max="90" value="{{ $isEditing ? old('latitude') : $node['latitude'] }}" placeholder="e.g. 10.354270" aria-describedby="coordinate-help-{{ $nodeId }}">
                        </div>
                        <div class="field">
                            <label for="longitude-{{ $nodeId }}">Longitude</label>
                            <input class="input" id="longitude-{{ $nodeId }}" name="longitude" type="number" step="any" min="-180" max="180" value="{{ $isEditing ? old('longitude') : $node['longitude'] }}" placeholder="e.g. 124.970400" aria-describedby="coordinate-help-{{ $nodeId }}">
                        </div>
                        <div class="node-location-save">
                            <p id="coordinate-help-{{ $nodeId }}">Use decimal numbers with a dot. Enter both coordinates, or leave both blank to remove the pin.</p>
                            <button class="btn btn-primary" type="submit">Save location</button>
                        </div>
                    </form>
                </details>
            @endforeach
        </div>
    </section>
@endsection
