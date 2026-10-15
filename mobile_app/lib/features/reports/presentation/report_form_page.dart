import 'dart:io';

import 'package:flutter/material.dart';
import 'package:geocoding/geocoding.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';
import 'package:video_player/video_player.dart';

import '../../../core/network/stitch_api_exception.dart';
import '../../../core/session/stitch_session_controller.dart';
import '../../shared/models/stitch_models.dart';

class ReportFormPage extends StatefulWidget {
  const ReportFormPage({
    super.key,
    required this.sessionController,
  });

  final StitchSessionController sessionController;

  @override
  State<ReportFormPage> createState() => _ReportFormPageState();
}

class _ReportFormPageState extends State<ReportFormPage> {
  final _formKey = GlobalKey<FormState>();
  final _locationController = TextEditingController();
  final _descriptionController = TextEditingController();
  final _latitudeController = TextEditingController();
  final _longitudeController = TextEditingController();
  final _picker = ImagePicker();

  String _incidentType = 'General Emergency';
  String _transmissionType = 'online';
  String _severityPreference = '';
  bool _submitting = false;
  bool _fetchingGps = false;
  XFile? _evidenceFile;
  String _evidenceType = 'none';
  XFile? _selfieFile;

  StitchUser get _user => widget.sessionController.currentUser!;
  bool get _isCivilian => _user.isCivilian;

  @override
  void dispose() {
    _locationController.dispose();
    _descriptionController.dispose();
    _latitudeController.dispose();
    _longitudeController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final hasPhoto = _evidenceFile != null && _evidenceType == 'photo';
    final hasVideo = _evidenceFile != null && _evidenceType == 'video';

    return Scaffold(
      appBar: AppBar(
        title: Text(_isCivilian ? 'Emergency Report' : 'Manual Incident Entry'),
      ),
      body: SafeArea(
        child: DecoratedBox(
          decoration: const BoxDecoration(
            gradient: LinearGradient(
              colors: [Color(0xFFF7FBFF), Color(0xFFEFF5FA)],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
            child: LayoutBuilder(
              builder: (context, constraints) {
                final stackFormFields = constraints.maxWidth < 640;
                final stackSubmitButtons = constraints.maxWidth < 720;

                return Form(
                  key: _formKey,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                  Container(
                    padding: const EdgeInsets.all(24),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(30),
                      gradient: const LinearGradient(
                        colors: [Color(0xFF081A24), Color(0xFF12303D), Color(0xFFB81620)],
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                      ),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _isCivilian
                              ? 'Send emergency details with AI severity support.'
                              : 'Create a field-ready manual incident report.',
                          style: theme.textTheme.displaySmall?.copyWith(color: Colors.white, height: 1.05),
                        ),
                        const SizedBox(height: 14),
                        Text(
                          _isCivilian
                              ? 'Capture evidence, verify with a live selfie, attach GPS coordinates, and choose between online transmission or LoRa fallback.'
                              : 'Use this responder form for manual scene entry, quick verification, and dispatch-ready details.',
                          style: theme.textTheme.bodyLarge?.copyWith(color: Colors.white.withValues(alpha: 0.82)),
                        ),
                        const SizedBox(height: 18),
                        Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            _ModeChip(label: _user.roleLabel, color: Colors.white),
                            _ModeChip(
                              label: _transmissionType == 'online' ? 'FULL ONLINE REPORT' : 'LORA COMPACT MODE',
                              color: Colors.white,
                            ),
                            _ModeChip(
                              label: hasPhoto
                                  ? 'PHOTO ATTACHED'
                                  : hasVideo
                                      ? 'VIDEO ATTACHED'
                                      : 'NO MEDIA YET',
                              color: Colors.white,
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 18),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(22),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('Core incident details', style: theme.textTheme.titleLarge),
                          const SizedBox(height: 16),
                          DropdownButtonFormField<String>(
                            initialValue: _incidentType,
                            decoration: const InputDecoration(
                              labelText: 'Incident type',
                              prefixIcon: Icon(Icons.category_rounded),
                            ),
                            items: const [
                              DropdownMenuItem(value: 'General Emergency', child: Text('General Emergency')),
                              DropdownMenuItem(value: 'Vehicular Accident', child: Text('Vehicular Accident')),
                              DropdownMenuItem(value: 'Road Accident', child: Text('Road Accident')),
                              DropdownMenuItem(value: 'Landslide', child: Text('Landslide')),
                              DropdownMenuItem(value: 'Flooding', child: Text('Flooding')),
                              DropdownMenuItem(value: 'Fire Incident', child: Text('Fire Incident')),
                              DropdownMenuItem(value: 'Medical Emergency', child: Text('Medical Emergency')),
                            ],
                            onChanged: (value) => setState(() => _incidentType = value ?? 'General Emergency'),
                          ),
                          const SizedBox(height: 16),
                          _TwoColumnLayout(
                            stackVertically: stackFormFields,
                            start: DropdownButtonFormField<String>(
                              initialValue: _transmissionType,
                              decoration: const InputDecoration(
                                labelText: 'Transmission mode',
                                prefixIcon: Icon(Icons.hub_rounded),
                              ),
                              items: const [
                                DropdownMenuItem(value: 'online', child: Text('Online mode')),
                                DropdownMenuItem(value: 'lora', child: Text('LoRa fallback mode')),
                              ],
                              onChanged: (value) => setState(() => _transmissionType = value ?? 'online'),
                            ),
                            end: DropdownButtonFormField<String>(
                              initialValue: _severityPreference,
                              decoration: const InputDecoration(
                                labelText: 'Severity preference',
                                prefixIcon: Icon(Icons.local_fire_department_rounded),
                              ),
                              items: const [
                                DropdownMenuItem(value: '', child: Text('AI detect')),
                                DropdownMenuItem(value: 'Minor', child: Text('Minor')),
                                DropdownMenuItem(value: 'Serious', child: Text('Serious')),
                                DropdownMenuItem(value: 'Fatal', child: Text('Fatal')),
                              ],
                              onChanged: (value) => setState(() => _severityPreference = value ?? ''),
                            ),
                          ),
                          const SizedBox(height: 16),
                          TextFormField(
                            controller: _descriptionController,
                            minLines: 4,
                            maxLines: 7,
                            decoration: const InputDecoration(
                              labelText: 'Short description',
                              alignLabelWithHint: true,
                              prefixIcon: Padding(
                                padding: EdgeInsets.only(bottom: 68),
                                child: Icon(Icons.description_rounded),
                              ),
                            ),
                            validator: (value) => value == null || value.trim().isEmpty ? 'Describe the incident.' : null,
                          ),
                          const SizedBox(height: 16),
                          TextFormField(
                            controller: _locationController,
                            decoration: const InputDecoration(
                              labelText: 'Location description',
                              prefixIcon: Icon(Icons.place_rounded),
                            ),
                            validator: (value) => value == null || value.trim().isEmpty ? 'Enter the location description.' : null,
                          ),
                          const SizedBox(height: 16),
                          _TwoColumnLayout(
                            stackVertically: stackFormFields,
                            start: TextFormField(
                              controller: _latitudeController,
                              keyboardType: const TextInputType.numberWithOptions(decimal: true, signed: true),
                              decoration: const InputDecoration(
                                labelText: 'Latitude',
                                prefixIcon: Icon(Icons.explore_rounded),
                              ),
                            ),
                            end: TextFormField(
                              controller: _longitudeController,
                              keyboardType: const TextInputType.numberWithOptions(decimal: true, signed: true),
                              decoration: const InputDecoration(
                                labelText: 'Longitude',
                                prefixIcon: Icon(Icons.map_rounded),
                              ),
                            ),
                          ),
                          const SizedBox(height: 14),
                          SizedBox(
                            width: double.infinity,
                            child: OutlinedButton.icon(
                              onPressed: _fetchingGps ? null : _useCurrentGps,
                              icon: _fetchingGps
                                  ? const SizedBox(
                                      width: 18,
                                      height: 18,
                                      child: CircularProgressIndicator(strokeWidth: 2),
                                    )
                                  : const Icon(Icons.gps_fixed_rounded),
                              label: Text(_fetchingGps ? 'Getting GPS...' : 'Use Current Latitude and Longitude'),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 18),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(22),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('Evidence upload', style: theme.textTheme.titleLarge),
                          const SizedBox(height: 8),
                          Text(
                            _transmissionType == 'online'
                                ? 'Online mode sends full evidence together with AI-assisted severity analysis.'
                                : 'LoRa fallback sends compact data only. Media can still be captured for later online submission.',
                            style: theme.textTheme.bodyMedium,
                          ),
                          const SizedBox(height: 18),
                          Wrap(
                            spacing: 12,
                            runSpacing: 12,
                            children: [
                              OutlinedButton.icon(
                                onPressed: _capturePhoto,
                                icon: const Icon(Icons.camera_alt_rounded),
                                label: const Text('Capture photo'),
                              ),
                              OutlinedButton.icon(
                                onPressed: _captureVideo,
                                icon: const Icon(Icons.videocam_rounded),
                                label: const Text('Capture video'),
                              ),
                              if (_evidenceFile != null)
                                OutlinedButton.icon(
                                  onPressed: _clearEvidence,
                                  icon: const Icon(Icons.delete_outline_rounded),
                                  label: const Text('Clear evidence'),
                                ),
                            ],
                          ),
                          const SizedBox(height: 18),
                          if (hasPhoto)
                            _PreviewCard(title: 'Captured photo evidence', file: File(_evidenceFile!.path), footer: _evidenceFile!.name)
                          else if (hasVideo)
                            _VideoPreviewCard(file: File(_evidenceFile!.path), fileName: _evidenceFile!.name)
                          else
                            const _InfoHintCard(
                              title: 'No evidence selected yet',
                              message: 'Capture a photo or video if there is visual proof of the incident.',
                              icon: Icons.photo_camera_back_rounded,
                            ),
                        ],
                      ),
                    ),
                  ),
                  if (_isCivilian) ...[
                    const SizedBox(height: 18),
                    Card(
                      child: Padding(
                        padding: const EdgeInsets.all(22),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('Realtime selfie verification', style: theme.textTheme.titleLarge),
                            const SizedBox(height: 8),
                            Text(
                              'Before a civilian report is sent, the app requires a live front-camera selfie to discourage false emergency submissions.',
                              style: theme.textTheme.bodyMedium,
                            ),
                            const SizedBox(height: 18),
                            Wrap(
                              spacing: 12,
                              runSpacing: 12,
                              children: [
                                OutlinedButton.icon(
                                  onPressed: _captureSelfie,
                                  icon: const Icon(Icons.camera_front_rounded),
                                  label: Text(_selfieFile == null ? 'Capture verification selfie' : 'Retake selfie'),
                                ),
                                if (_selfieFile != null)
                                  OutlinedButton.icon(
                                    onPressed: () => setState(() => _selfieFile = null),
                                    icon: const Icon(Icons.delete_outline_rounded),
                                    label: const Text('Clear selfie'),
                                  ),
                              ],
                            ),
                            const SizedBox(height: 18),
                            if (_selfieFile != null)
                              _PreviewCard(title: 'Verification selfie', file: File(_selfieFile!.path), footer: _selfieFile!.name)
                            else
                              const _InfoHintCard(
                                title: 'Selfie not captured yet',
                                message: 'When you tap send without a selfie, the front camera will open automatically for verification.',
                                icon: Icons.verified_user_rounded,
                              ),
                          ],
                        ),
                      ),
                    ),
                  ],
                  const SizedBox(height: 18),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(22),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('Before you send', style: theme.textTheme.titleLarge),
                          const SizedBox(height: 8),
                          Text(
                            _transmissionType == 'online'
                                ? 'Online mode sends description, severity, GPS, and media. Photo uploads can be forwarded to the AI severity service.'
                                : 'LoRa mode sends a compact emergency payload with severity, GPS, and description. Media stays local until online mode is restored.',
                            style: theme.textTheme.bodyMedium,
                          ),
                          const SizedBox(height: 16),
                          Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              _ModeChip(label: _transmissionType == 'online' ? 'ONLINE' : 'LORA', color: const Color(0xFF005FB2)),
                              _ModeChip(
                                label: _latitudeController.text.trim().isNotEmpty && _longitudeController.text.trim().isNotEmpty ? 'GPS READY' : 'GPS REQUIRED',
                                color: const Color(0xFF7C5C0A),
                              ),
                              _ModeChip(
                                label: _selfieFile != null || !_isCivilian ? 'SELFIE READY' : 'SELFIE REQUIRED',
                                color: const Color(0xFF7A3FA0),
                              ),
                            ],
                          ),
                          const SizedBox(height: 20),
                          if (stackSubmitButtons)
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                FilledButton.icon(
                                  onPressed: _submitting ? null : _submit,
                                  icon: _submitting
                                      ? const SizedBox(
                                          width: 18,
                                          height: 18,
                                          child: CircularProgressIndicator(strokeWidth: 2),
                                        )
                                      : Icon(_isCivilian && _selfieFile == null ? Icons.camera_front_rounded : Icons.send_rounded),
                                  label: Text(
                                    _submitting
                                        ? 'Submitting...'
                                        : _isCivilian && _selfieFile == null
                                            ? 'Verify Selfie and Send Emergency Report'
                                            : 'Send Emergency Report',
                                  ),
                                ),
                                const SizedBox(height: 12),
                                OutlinedButton(
                                  onPressed: _submitting ? null : () => Navigator.of(context).pop(),
                                  child: const Text('Cancel'),
                                ),
                              ],
                            )
                          else
                            Row(
                              children: [
                                Expanded(
                                  child: FilledButton.icon(
                                    onPressed: _submitting ? null : _submit,
                                    icon: _submitting
                                        ? const SizedBox(
                                            width: 18,
                                            height: 18,
                                            child: CircularProgressIndicator(strokeWidth: 2),
                                          )
                                        : Icon(_isCivilian && _selfieFile == null ? Icons.camera_front_rounded : Icons.send_rounded),
                                    label: Text(
                                      _submitting
                                          ? 'Submitting...'
                                          : _isCivilian && _selfieFile == null
                                              ? 'Verify Selfie and Send Emergency Report'
                                              : 'Send Emergency Report',
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 12),
                                OutlinedButton(
                                  onPressed: _submitting ? null : () => Navigator.of(context).pop(),
                                  child: const Text('Cancel'),
                                ),
                              ],
                            ),
                        ],
                      ),
                    ),
                  ),
                    ],
                  ),
                );
              },
            ),
          ),
        ),
      ),
    );
  }

  Future<void> _useCurrentGps() async {
    setState(() => _fetchingGps = true);
    try {
      var enabled = await Geolocator.isLocationServiceEnabled();
      if (!enabled) {
        await Geolocator.openLocationSettings();
        enabled = await Geolocator.isLocationServiceEnabled();
      }
      if (!enabled) {
        throw const StitchApiException(message: 'Location services are disabled on this device.', statusCode: 0);
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.deniedForever) {
        await Geolocator.openAppSettings();
        throw const StitchApiException(message: 'Location permission is permanently denied. Enable it in app settings.', statusCode: 0);
      }
      if (permission == LocationPermission.denied) {
        throw const StitchApiException(message: 'Location permission is required to fetch latitude and longitude.', statusCode: 0);
      }

      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
        ),
      );
      final resolvedLocation = await _buildIncidentLocation(position);
      final fallbackLocation = 'GPS ${position.latitude.toStringAsFixed(5)}, ${position.longitude.toStringAsFixed(5)}';
      if (!mounted) {
        return;
      }
      setState(() {
        _latitudeController.text = position.latitude.toStringAsFixed(6);
        _longitudeController.text = position.longitude.toStringAsFixed(6);
        _locationController.text = resolvedLocation ?? fallbackLocation;
      });
      _showMessage(resolvedLocation == null
          ? 'GPS coordinates captured successfully.'
          : 'Current location captured successfully.');
    } on StitchApiException catch (error) {
      _showMessage(error.message);
    } catch (_) {
      _showMessage('Unable to get the current GPS location. Please try again.');
    } finally {
      if (mounted) {
        setState(() => _fetchingGps = false);
      }
    }
  }

  Future<void> _capturePhoto() async {
    await _pickEvidence('photo', () => _picker.pickImage(source: ImageSource.camera, imageQuality: 90));
  }

  Future<void> _captureVideo() async {
    await _pickEvidence('video', () => _picker.pickVideo(source: ImageSource.camera, maxDuration: const Duration(minutes: 2)));
  }

  Future<void> _pickEvidence(String media, Future<XFile?> Function() action) async {
    try {
      final file = await action();
      if (file == null || !mounted) {
        return;
      }
      setState(() {
        _evidenceFile = file;
        _evidenceType = media;
      });
    } catch (_) {
      _showMessage('Unable to capture $media evidence on this device.');
    }
  }

  Future<void> _captureSelfie() async {
    try {
      final file = await _picker.pickImage(
        source: ImageSource.camera,
        preferredCameraDevice: CameraDevice.front,
        imageQuality: 90,
      );
      if (file == null || !mounted) {
        return;
      }
      setState(() => _selfieFile = file);
    } catch (_) {
      _showMessage('Unable to open the front camera for selfie verification.');
    }
  }

  void _clearEvidence() {
    setState(() {
      _evidenceFile = null;
      _evidenceType = 'none';
    });
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) {
      return;
    }

    final gpsReady = await _ensureAccurateLocation();
    if (!gpsReady || !mounted) {
      return;
    }

    if (_isCivilian && _selfieFile == null) {
      await _captureSelfie();
      if (!mounted) {
        return;
      }
      if (_selfieFile != null) {
        _showMessage('Selfie captured. Review your details, then tap send again.');
      }
      return;
    }

    setState(() => _submitting = true);
    try {
      final effectiveEvidenceType = _transmissionType == 'online' ? _evidenceType : 'none';
      final report = await widget.sessionController.createReport(
        incidentType: _incidentType,
        severity: _severityPreference,
        locationText: _locationController.text.trim(),
        description: _descriptionController.text.trim(),
        transmissionType: _transmissionType,
        evidenceType: effectiveEvidenceType,
        evidenceFile: effectiveEvidenceType == 'none' ? null : File(_evidenceFile!.path),
        evidenceFilename: effectiveEvidenceType == 'none' ? null : _evidenceFile!.name,
        selfieFile: _selfieFile == null ? null : File(_selfieFile!.path),
        selfieFilename: _selfieFile?.name,
        latitude: double.tryParse(_latitudeController.text.trim()),
        longitude: double.tryParse(_longitudeController.text.trim()),
      );

      if (!mounted) {
        return;
      }
      await showDialog<void>(
        context: context,
        builder: (context) => _SubmissionDialog(report: report),
      );
      if (mounted) {
        Navigator.of(context).pop(true);
      }
    } on StitchApiException catch (error) {
      _showMessage(error.message);
    } finally {
      if (mounted) {
        setState(() => _submitting = false);
      }
    }
  }

  Future<bool> _ensureAccurateLocation() async {
    final hasGps = _latitudeController.text.trim().isNotEmpty && _longitudeController.text.trim().isNotEmpty;

    if (hasGps) {
      return true;
    }

    await _useCurrentGps();

    final updatedHasGps = _latitudeController.text.trim().isNotEmpty && _longitudeController.text.trim().isNotEmpty;
    if (!updatedHasGps) {
      _showMessage('Current GPS location is required before sending the emergency report.');
    }

    return updatedHasGps;
  }

  Future<String?> _buildIncidentLocation(Position position) async {
    try {
      final placemarks = await placemarkFromCoordinates(position.latitude, position.longitude);
      if (placemarks.isEmpty) {
        return null;
      }

      final placemark = placemarks.first;
      final parts = <String?>[
        placemark.street,
        placemark.subLocality,
        placemark.locality,
        placemark.subAdministrativeArea,
        placemark.administrativeArea,
      ];

      final uniqueParts = <String>[];
      for (final part in parts) {
        final trimmed = part?.trim();
        if (trimmed == null || trimmed.isEmpty) {
          continue;
        }
        if (uniqueParts.any((existing) => existing.toLowerCase() == trimmed.toLowerCase())) {
          continue;
        }
        uniqueParts.add(trimmed);
      }

      final address = uniqueParts.take(4).join(', ');
      final gpsText = 'GPS ${position.latitude.toStringAsFixed(5)}, ${position.longitude.toStringAsFixed(5)}';

      if (address.isEmpty) {
        return gpsText;
      }

      return '$address | $gpsText';
    } catch (_) {
      return null;
    }
  }

  void _showMessage(String message) {
    if (!mounted) {
      return;
    }
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }
}

class _TwoColumnLayout extends StatelessWidget {
  const _TwoColumnLayout({
    required this.stackVertically,
    required this.start,
    required this.end,
  });

  final bool stackVertically;
  final Widget start;
  final Widget end;

  @override
  Widget build(BuildContext context) {
    if (stackVertically) {
      return Column(
        children: [
          start,
          const SizedBox(height: 12),
          end,
        ],
      );
    }

    return Row(
      children: [
        Expanded(child: start),
        const SizedBox(width: 12),
        Expanded(child: end),
      ],
    );
  }
}

class _PreviewCard extends StatelessWidget {
  const _PreviewCard({
    required this.title,
    required this.file,
    required this.footer,
  });

  final String title;
  final File file;
  final String footer;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: Theme.of(context).textTheme.labelLarge),
        const SizedBox(height: 10),
        ClipRRect(
          borderRadius: BorderRadius.circular(22),
          child: Image.file(file, width: double.infinity, height: 220, fit: BoxFit.cover),
        ),
        const SizedBox(height: 10),
        Text(footer, style: Theme.of(context).textTheme.bodyMedium),
      ],
    );
  }
}

class _VideoPreviewCard extends StatefulWidget {
  const _VideoPreviewCard({
    required this.file,
    required this.fileName,
  });

  final File file;
  final String fileName;

  @override
  State<_VideoPreviewCard> createState() => _VideoPreviewCardState();
}

class _VideoPreviewCardState extends State<_VideoPreviewCard> {
  late final VideoPlayerController _controller;
  bool _ready = false;

  @override
  void initState() {
    super.initState();
    _controller = VideoPlayerController.file(widget.file)
      ..setLooping(true)
      ..initialize().then((_) {
        if (!mounted) {
          return;
        }
        setState(() => _ready = true);
      });
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: const Color(0xFFF7FAFC),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: const Color(0xFFDCE6EE)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Captured video evidence', style: Theme.of(context).textTheme.labelLarge),
          const SizedBox(height: 12),
          ClipRRect(
            borderRadius: BorderRadius.circular(18),
            child: AspectRatio(
              aspectRatio: _ready ? _controller.value.aspectRatio : 16 / 9,
              child: DecoratedBox(
                decoration: const BoxDecoration(color: Color(0xFF081A24)),
                child: _ready
                    ? Stack(
                        alignment: Alignment.center,
                        children: [
                          VideoPlayer(_controller),
                          IconButton.filled(
                            onPressed: () {
                              if (_controller.value.isPlaying) {
                                _controller.pause();
                              } else {
                                _controller.play();
                              }
                              setState(() {});
                            },
                            icon: Icon(
                              _controller.value.isPlaying ? Icons.pause_rounded : Icons.play_arrow_rounded,
                            ),
                          ),
                        ],
                      )
                    : const Center(child: CircularProgressIndicator()),
              ),
            ),
          ),
          const SizedBox(height: 8),
          Text(widget.fileName, style: Theme.of(context).textTheme.bodyMedium),
          const SizedBox(height: 8),
          Text(
            'Video preview is ready. In online mode, this file will be sent to Laravel together with the rest of the emergency report.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
        ],
      ),
    );
  }
}

class _InfoHintCard extends StatelessWidget {
  const _InfoHintCard({
    required this.title,
    required this.message,
    required this.icon,
  });

  final String title;
  final String message;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FBFD),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: const Color(0xFFDCE6EE)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 46,
            height: 46,
            decoration: BoxDecoration(
              color: const Color(0xFFEAF2FF),
              borderRadius: BorderRadius.circular(16),
            ),
            child: Icon(icon, color: const Color(0xFF005FB2)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: Theme.of(context).textTheme.labelLarge),
                const SizedBox(height: 6),
                Text(message, style: Theme.of(context).textTheme.bodyMedium),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _ModeChip extends StatelessWidget {
  const _ModeChip({required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: Theme.of(context).textTheme.labelLarge?.copyWith(color: color),
      ),
    );
  }
}

class _SubmissionDialog extends StatelessWidget {
  const _SubmissionDialog({required this.report});

  final IncidentReport report;

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Emergency Report Sent'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(report.reportCode, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 10),
          Text(report.locationText),
          const SizedBox(height: 10),
          Text('Severity: ${report.severity}'),
          const SizedBox(height: 6),
          Text('Transmission: ${report.transmissionType.toUpperCase()}'),
          const SizedBox(height: 6),
          Text('AI source: ${_aiSourceLabel(report)}'),
          if (report.aiSummary.isNotEmpty) ...[
            const SizedBox(height: 10),
            Text(report.aiSummary),
          ],
        ],
      ),
      actions: [
        FilledButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Continue'),
        ),
      ],
    );
  }
}

String _aiSourceLabel(IncidentReport report) {
  switch (report.aiSource) {
    case 'python_model':
      return 'AI image model';
    case 'description_fallback':
      return 'Description fallback';
    default:
      return report.aiStatus.isEmpty ? 'Pending' : report.aiStatus;
  }
}

