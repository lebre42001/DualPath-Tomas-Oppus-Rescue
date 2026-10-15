import 'package:flutter/material.dart';
import 'package:video_player/video_player.dart';

import '../../../core/network/stitch_api_exception.dart';
import '../../../core/session/stitch_session_controller.dart';
import '../../shared/models/stitch_models.dart';

class ReportsPage extends StatefulWidget {
  const ReportsPage({
    super.key,
    required this.sessionController,
    required this.onCreateReport,
  });

  final StitchSessionController sessionController;
  final VoidCallback onCreateReport;

  @override
  State<ReportsPage> createState() => ReportsPageState();
}

class ReportsPageState extends State<ReportsPage> {
  late Future<List<IncidentReport>> _reportsFuture;

  @override
  void initState() {
    super.initState();
    _reportsFuture = widget.sessionController.fetchReports();
  }

  Future<void> refresh() async {
    setState(() {
      _reportsFuture = widget.sessionController.fetchReports();
    });

    await _reportsFuture;
  }

  @override
  Widget build(BuildContext context) {
    final user = widget.sessionController.currentUser!;

    return FutureBuilder<List<IncidentReport>>(
      future: _reportsFuture,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }

        if (snapshot.hasError) {
          final message = snapshot.error is StitchApiException
              ? (snapshot.error as StitchApiException).message
              : 'Unable to load reports.';
          return Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(message, textAlign: TextAlign.center),
                  const SizedBox(height: 14),
                  FilledButton(
                    onPressed: refresh,
                    child: const Text('Retry'),
                  ),
                ],
              ),
            ),
          );
        }

        final reports = snapshot.data!;
        return RefreshIndicator(
          onRefresh: refresh,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 120),
            children: [
              _HeaderCard(
                title: user.isCivilian ? 'My report history' : 'Responder incident feed',
                description: user.isCivilian
                    ? 'Track your emergency submissions, AI severity results, transmission mode, and responder updates from this mobile history page.'
                    : 'Review live incoming incidents with evidence flags, AI severity metadata, GPS coordinates, and assignment context.',
                buttonLabel: user.isCivilian ? 'Send new report' : 'Create manual report',
                onPressed: widget.onCreateReport,
              ),
              const SizedBox(height: 18),
              if (reports.isEmpty)
                _EmptyStateCard(
                  title: user.isCivilian ? 'No reports yet' : 'No incidents in the feed',
                  message: user.isCivilian
                      ? 'Your emergency reports will appear here once you send the first mobile submission.'
                      : 'Pull down to refresh after a new civilian report reaches the Laravel dashboard.',
                )
              else
                ...reports.map(
                  (report) => Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: user.isCivilian
                        ? _CivilianHistoryCard(report: report)
                        : _ResponderIncidentCard(
                            report: report,
                            authHeaders: widget.sessionController.authHeaders,
                          ),
                  ),
                ),
            ],
          ),
        );
      },
    );
  }
}

class _HeaderCard extends StatelessWidget {
  const _HeaderCard({
    required this.title,
    required this.description,
    required this.buttonLabel,
    required this.onPressed,
  });

  final String title;
  final String description;
  final String buttonLabel;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(22),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: Theme.of(context).textTheme.headlineMedium),
            const SizedBox(height: 8),
            Text(description, style: Theme.of(context).textTheme.bodyMedium),
            const SizedBox(height: 18),
            FilledButton.icon(
              onPressed: onPressed,
              icon: const Icon(Icons.add_alert_rounded),
              label: Text(buttonLabel),
            ),
          ],
        ),
      ),
    );
  }
}

class _CivilianHistoryCard extends StatelessWidget {
  const _CivilianHistoryCard({required this.report});

  final IncidentReport report;

  @override
  Widget build(BuildContext context) {
    final severityColor = _severityColor(report.severity);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    report.incidentType,
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                  decoration: BoxDecoration(
                    color: severityColor.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: Text(
                    report.severity,
                    style: Theme.of(context).textTheme.labelLarge?.copyWith(color: severityColor),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(report.locationText),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _HistoryChip(label: report.status.toUpperCase(), color: const Color(0xFF005FB2)),
                _HistoryChip(label: report.transmissionType.toUpperCase(), color: const Color(0xFF0A7B56)),
                if (report.evidenceAvailable)
                  _HistoryChip(
                    label: report.hasVideoEvidence ? 'VIDEO ATTACHED' : 'PHOTO ATTACHED',
                    color: const Color(0xFF8A5E00),
                  ),
                if (report.selfieAvailable)
                  const _HistoryChip(label: 'SELFIE VERIFIED', color: Color(0xFF7A3FA0)),
              ],
            ),
            const SizedBox(height: 14),
            _StatusProgress(report: report),
            const SizedBox(height: 14),
            _InfoRow(label: 'AI source', value: _aiSourceLabel(report)),
            _InfoRow(label: 'Confidence', value: report.aiConfidence > 0 ? '${report.aiConfidence}%' : 'Pending'),
            _InfoRow(label: 'Transport', value: report.channel),
            if (report.hasGps)
              _InfoRow(
                label: 'Coordinates',
                value: '${report.latitude!.toStringAsFixed(5)}, ${report.longitude!.toStringAsFixed(5)}',
              ),
            const SizedBox(height: 12),
            Text(
              report.aiSummary.isNotEmpty ? report.aiSummary : report.description,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            if (report.aiErrorMessage.isNotEmpty) ...[
              const SizedBox(height: 10),
              Text(
                'AI fallback: ${report.aiErrorMessage}',
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: const Color(0xFFB81620)),
              ),
            ],
            const SizedBox(height: 12),
            Text(
              '${report.reportCode}  |  ${_formatTimestamp(report.createdAt)}',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ],
        ),
      ),
    );
  }
}

class _ResponderIncidentCard extends StatelessWidget {
  const _ResponderIncidentCard({
    required this.report,
    required this.authHeaders,
  });

  final IncidentReport report;
  final Map<String, String> authHeaders;

  @override
  Widget build(BuildContext context) {
    final severityColor = _severityColor(report.severity);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 6,
                  height: 72,
                  decoration: BoxDecoration(
                    color: severityColor,
                    borderRadius: BorderRadius.circular(999),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        report.incidentType,
                        style: Theme.of(context).textTheme.titleLarge,
                      ),
                      const SizedBox(height: 4),
                      Text(report.locationText),
                      const SizedBox(height: 8),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          _HistoryChip(label: report.severity.toUpperCase(), color: severityColor),
                          _HistoryChip(label: report.status.toUpperCase(), color: const Color(0xFF005FB2)),
                          _HistoryChip(label: report.transmissionType.toUpperCase(), color: const Color(0xFF0A7B56)),
                          if (report.evidenceAvailable)
                            _HistoryChip(
                              label: report.hasVideoEvidence ? 'VIDEO ATTACHED' : 'PHOTO ATTACHED',
                              color: const Color(0xFF8A5E00),
                            ),
                          if (report.selfieAvailable)
                            const _HistoryChip(label: 'SELFIE VERIFIED', color: Color(0xFF7A3FA0)),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 14),
            _InfoRow(label: 'Reporter', value: report.reporterName.isEmpty ? 'Unknown' : report.reporterName),
            _ResponderAiQuickRead(report: report),
            const SizedBox(height: 12),
            if (report.evidenceAvailable || report.selfieAvailable)
              _ResponderMediaPreview(
                report: report,
                authHeaders: authHeaders,
              ),
            if (report.evidenceAvailable || report.selfieAvailable)
              const SizedBox(height: 12),
            if (report.assignedResponderName.isNotEmpty)
              _InfoRow(label: 'Assigned', value: report.assignedResponderName),
            if (report.hasGps)
              _InfoRow(
                label: 'Coordinates',
                value: '${report.latitude!.toStringAsFixed(5)}, ${report.longitude!.toStringAsFixed(5)}',
              ),
            if (report.responseNotes.isNotEmpty)
              _InfoRow(label: 'Responder note', value: report.responseNotes),
            const SizedBox(height: 12),
            Text(
              report.aiSummary.isNotEmpty ? report.aiSummary : report.description,
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 12),
            Text(
              '${report.reportCode}  |  ${_formatTimestamp(report.createdAt)}',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ],
        ),
      ),
    );
  }
}

class _ResponderMediaPreview extends StatelessWidget {
  const _ResponderMediaPreview({
    required this.report,
    required this.authHeaders,
  });

  final IncidentReport report;
  final Map<String, String> authHeaders;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFFF7FAFD),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: const Color(0xFFD7E3EE)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Attached media', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 6),
          Text(
            'Review the civilian evidence and verification selfie directly from the responder feed.',
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          const SizedBox(height: 12),
          if (report.evidenceAvailable)
            _ResponderPreviewCard(
              title: report.hasVideoEvidence ? 'Video evidence' : 'Photo evidence',
              filename: report.evidenceOriginalName.isEmpty ? 'Attached evidence' : report.evidenceOriginalName,
              hint: report.hasVideoEvidence ? 'Tap to expand and play video' : 'Tap to enlarge photo',
              onTap: () => _openMediaViewer(
                context,
                title: report.hasVideoEvidence ? 'Video evidence' : 'Photo evidence',
                url: report.evidenceUrl,
                authHeaders: authHeaders,
                isVideo: report.hasVideoEvidence,
              ),
              child: report.hasVideoEvidence
                  ? _SecureVideoPreview(url: report.evidenceUrl, authHeaders: authHeaders, height: 240)
                  : _SecureImagePreview(url: report.evidenceUrl, authHeaders: authHeaders, height: 220),
            ),
          if (report.evidenceAvailable && report.selfieAvailable) const SizedBox(height: 12),
          if (report.selfieAvailable)
            _ResponderPreviewCard(
              title: 'Verification selfie',
              filename: report.selfieOriginalName.isEmpty ? 'Verification selfie' : report.selfieOriginalName,
              hint: 'Tap to enlarge verification selfie',
              onTap: () => _openMediaViewer(
                context,
                title: 'Verification selfie',
                url: report.selfieUrl,
                authHeaders: authHeaders,
                isVideo: false,
              ),
              child: _SecureImagePreview(url: report.selfieUrl, authHeaders: authHeaders, height: 220),
            ),
        ],
      ),
    );
  }
}

class _ResponderPreviewCard extends StatelessWidget {
  const _ResponderPreviewCard({
    required this.title,
    required this.filename,
    required this.hint,
    required this.onTap,
    required this.child,
  });

  final String title;
  final String filename;
  final String hint;
  final VoidCallback onTap;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(18),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(18),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: const Color(0xFFE1EAF2)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(title, style: Theme.of(context).textTheme.labelLarge),
                  ),
                  const Icon(Icons.open_in_full_rounded, size: 18, color: Color(0xFF6A7C90)),
                ],
              ),
              const SizedBox(height: 10),
              child,
              const SizedBox(height: 10),
              Text(filename, style: Theme.of(context).textTheme.bodyMedium),
              const SizedBox(height: 4),
              Text(
                hint,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(color: const Color(0xFF5A6B7D)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _SecureImagePreview extends StatelessWidget {
  const _SecureImagePreview({
    required this.url,
    required this.authHeaders,
    this.height = 180,
  });

  final String url;
  final Map<String, String> authHeaders;
  final double height;

  @override
  Widget build(BuildContext context) {
    if (url.isEmpty) {
      return const _PreviewUnavailable(label: 'Image unavailable');
    }

    return ClipRRect(
      borderRadius: BorderRadius.circular(14),
      child: SizedBox(
        width: double.infinity,
        height: height,
        child: Image.network(
          url,
          headers: authHeaders,
          fit: BoxFit.cover,
          errorBuilder: (context, error, stackTrace) => const _PreviewUnavailable(label: 'Unable to load image'),
          loadingBuilder: (context, child, loadingProgress) {
            if (loadingProgress == null) {
              return child;
            }

            return const _PreviewLoading();
          },
        ),
      ),
    );
  }
}

class _SecureVideoPreview extends StatefulWidget {
  const _SecureVideoPreview({
    required this.url,
    required this.authHeaders,
    this.height = 210,
  });

  final String url;
  final Map<String, String> authHeaders;
  final double height;

  @override
  State<_SecureVideoPreview> createState() => _SecureVideoPreviewState();
}

class _SecureVideoPreviewState extends State<_SecureVideoPreview> {
  VideoPlayerController? _controller;
  bool _initialized = false;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _initialize();
  }

  Future<void> _initialize() async {
    if (widget.url.isEmpty) {
      setState(() {
        _failed = true;
      });
      return;
    }

    final controller = VideoPlayerController.networkUrl(
      Uri.parse(widget.url),
      httpHeaders: widget.authHeaders,
      videoPlayerOptions: VideoPlayerOptions(mixWithOthers: true),
    );

    try {
      await controller.initialize();
      await controller.setLooping(false);
      await controller.setVolume(0);
      if (!mounted) {
        await controller.dispose();
        return;
      }

      setState(() {
        _controller = controller;
        _initialized = true;
      });
    } catch (_) {
      await controller.dispose();
      if (!mounted) {
        return;
      }

      setState(() {
        _failed = true;
      });
    }
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_failed) {
      return const _PreviewUnavailable(label: 'Unable to load video');
    }

    if (!_initialized || _controller == null) {
      return SizedBox(
        height: widget.height,
        child: const _PreviewLoading(),
      );
    }

    final controller = _controller!;

    return ClipRRect(
      borderRadius: BorderRadius.circular(14),
      child: Container(
        color: Colors.black,
        height: widget.height,
        width: double.infinity,
        child: Stack(
          alignment: Alignment.center,
          children: [
            SizedBox.expand(
              child: FittedBox(
                fit: BoxFit.cover,
                child: SizedBox(
                  width: controller.value.size.width,
                  height: controller.value.size.height,
                  child: VideoPlayer(controller),
                ),
              ),
            ),
            Container(
              decoration: BoxDecoration(
                color: Colors.black.withValues(alpha: 0.22),
                shape: BoxShape.circle,
              ),
              child: IconButton(
                onPressed: () async {
                  if (controller.value.isPlaying) {
                    await controller.pause();
                  } else {
                    await controller.play();
                  }
                  if (mounted) {
                    setState(() {});
                  }
                },
                icon: Icon(
                  controller.value.isPlaying ? Icons.pause_rounded : Icons.play_arrow_rounded,
                  color: Colors.white,
                  size: 34,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PreviewLoading extends StatelessWidget {
  const _PreviewLoading();

  @override
  Widget build(BuildContext context) {
    return Container(
      color: const Color(0xFFEAF1F7),
      alignment: Alignment.center,
      child: const SizedBox(
        width: 24,
        height: 24,
        child: CircularProgressIndicator(strokeWidth: 2.4),
      ),
    );
  }
}

class _PreviewUnavailable extends StatelessWidget {
  const _PreviewUnavailable({
    required this.label,
    this.height = 180,
  });

  final String label;
  final double height;

  @override
  Widget build(BuildContext context) {
    return Container(
      height: height,
      width: double.infinity,
      decoration: BoxDecoration(
        color: const Color(0xFFEAF1F7),
        borderRadius: BorderRadius.circular(14),
      ),
      alignment: Alignment.center,
      child: Text(label, style: Theme.of(context).textTheme.bodyMedium),
    );
  }
}

Future<void> _openMediaViewer(
  BuildContext context, {
  required String title,
  required String url,
  required Map<String, String> authHeaders,
  required bool isVideo,
}) async {
  if (url.isEmpty) {
    return;
  }

  await Navigator.of(context).push(
    MaterialPageRoute<void>(
      builder: (_) => _FullScreenMediaViewer(
        title: title,
        url: url,
        authHeaders: authHeaders,
        isVideo: isVideo,
      ),
      fullscreenDialog: true,
    ),
  );
}

class _FullScreenMediaViewer extends StatelessWidget {
  const _FullScreenMediaViewer({
    required this.title,
    required this.url,
    required this.authHeaders,
    required this.isVideo,
  });

  final String title;
  final String url;
  final Map<String, String> authHeaders;
  final bool isVideo;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text(title),
      ),
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: isVideo
                ? _FullScreenVideoViewer(url: url, authHeaders: authHeaders)
                : _FullScreenImageViewer(url: url, authHeaders: authHeaders),
          ),
        ),
      ),
    );
  }
}

class _FullScreenImageViewer extends StatelessWidget {
  const _FullScreenImageViewer({
    required this.url,
    required this.authHeaders,
  });

  final String url;
  final Map<String, String> authHeaders;

  @override
  Widget build(BuildContext context) {
    return InteractiveViewer(
      minScale: 0.8,
      maxScale: 4,
      child: Image.network(
        url,
        headers: authHeaders,
        fit: BoxFit.contain,
        errorBuilder: (context, error, stackTrace) => const _PreviewUnavailable(
          label: 'Unable to load image',
          height: 260,
        ),
        loadingBuilder: (context, child, loadingProgress) {
          if (loadingProgress == null) {
            return child;
          }

          return const SizedBox(
            height: 260,
            child: _PreviewLoading(),
          );
        },
      ),
    );
  }
}

class _FullScreenVideoViewer extends StatefulWidget {
  const _FullScreenVideoViewer({
    required this.url,
    required this.authHeaders,
  });

  final String url;
  final Map<String, String> authHeaders;

  @override
  State<_FullScreenVideoViewer> createState() => _FullScreenVideoViewerState();
}

class _FullScreenVideoViewerState extends State<_FullScreenVideoViewer> {
  VideoPlayerController? _controller;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _initialize();
  }

  Future<void> _initialize() async {
    final controller = VideoPlayerController.networkUrl(
      Uri.parse(widget.url),
      httpHeaders: widget.authHeaders,
      videoPlayerOptions: VideoPlayerOptions(mixWithOthers: true),
    );

    try {
      await controller.initialize();
      if (!mounted) {
        await controller.dispose();
        return;
      }

      setState(() {
        _controller = controller;
      });
    } catch (_) {
      await controller.dispose();
      if (!mounted) {
        return;
      }

      setState(() {
        _failed = true;
      });
    }
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_failed) {
      return const _PreviewUnavailable(label: 'Unable to load video', height: 260);
    }

    final controller = _controller;
    if (controller == null || !controller.value.isInitialized) {
      return const SizedBox(height: 260, child: _PreviewLoading());
    }

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        AspectRatio(
          aspectRatio: controller.value.aspectRatio == 0 ? 16 / 9 : controller.value.aspectRatio,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(18),
            child: VideoPlayer(controller),
          ),
        ),
        const SizedBox(height: 16),
        FilledButton.icon(
          onPressed: () async {
            if (controller.value.isPlaying) {
              await controller.pause();
            } else {
              await controller.play();
            }
            if (mounted) {
              setState(() {});
            }
          },
          icon: Icon(controller.value.isPlaying ? Icons.pause_rounded : Icons.play_arrow_rounded),
          label: Text(controller.value.isPlaying ? 'Pause video' : 'Play video'),
        ),
      ],
    );
  }
}

class _ResponderAiQuickRead extends StatelessWidget {
  const _ResponderAiQuickRead({required this.report});

  final IncidentReport report;

  @override
  Widget build(BuildContext context) {
    final summary = report.aiSummary.isNotEmpty ? report.aiSummary : report.description;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFFF6FAFE),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: const Color(0xFFD8E5F0)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('AI quick read', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _HistoryChip(
                label: '${report.severity.toUpperCase()} OUTPUT',
                color: _severityColor(report.severity),
              ),
              _HistoryChip(
                label: report.aiConfidence > 0 ? 'CONFIDENCE ${report.aiConfidence}%' : 'CONFIDENCE PENDING',
                color: _aiConfidenceColor(report.aiConfidence),
              ),
              _HistoryChip(
                label: _aiSourceChipLabel(report),
                color: const Color(0xFF005FB2),
              ),
              _HistoryChip(
                label: _aiReviewChipLabel(report),
                color: _aiReviewColor(report),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Text(summary, style: Theme.of(context).textTheme.bodyMedium),
          const SizedBox(height: 10),
          _InfoRow(label: 'AI source', value: _aiSourceLabel(report)),
          _InfoRow(label: 'AI model', value: _aiModelLabel(report)),
          _InfoRow(label: 'Review state', value: _aiReviewStateLabel(report)),
          if (report.aiProcessedAt != null)
            _InfoRow(label: 'Processed', value: _formatTimestamp(report.aiProcessedAt)),
          if (report.aiErrorMessage.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                'AI fallback: ${report.aiErrorMessage}',
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: const Color(0xFFB81620)),
              ),
            ),
        ],
      ),
    );
  }
}

class _StatusProgress extends StatelessWidget {
  const _StatusProgress({required this.report});

  final IncidentReport report;

  @override
  Widget build(BuildContext context) {
    final status = report.status.toLowerCase();
    final progress = switch (status) {
      'received' => 0.25,
      'acknowledged' => 0.45,
      'dispatched' => 0.65,
      'responding' => 0.82,
      'resolved' => 1.0,
      'rejected' => 1.0,
      _ => 0.15,
    };

    final barColor = status == 'rejected'
        ? const Color(0xFFB81620)
        : status == 'resolved'
            ? const Color(0xFF0A7B56)
            : const Color(0xFF005FB2);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Text('Progress tracker', style: Theme.of(context).textTheme.labelLarge),
            const Spacer(),
            Text('${(progress * 100).round()}%', style: Theme.of(context).textTheme.labelLarge),
          ],
        ),
        const SizedBox(height: 8),
        ClipRRect(
          borderRadius: BorderRadius.circular(999),
          child: LinearProgressIndicator(
            minHeight: 10,
            value: progress,
            backgroundColor: const Color(0xFFE6EEF5),
            valueColor: AlwaysStoppedAnimation<Color>(barColor),
          ),
        ),
      ],
    );
  }
}

class _InfoRow extends StatelessWidget {
  const _InfoRow({
    required this.label,
    required this.value,
  });

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 110,
            child: Text(label, style: Theme.of(context).textTheme.bodyMedium),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(value.isEmpty ? '-' : value, style: Theme.of(context).textTheme.labelLarge),
          ),
        ],
      ),
    );
  }
}

class _HistoryChip extends StatelessWidget {
  const _HistoryChip({
    required this.label,
    required this.color,
  });

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: Theme.of(context).textTheme.labelLarge?.copyWith(color: color),
      ),
    );
  }
}

class _EmptyStateCard extends StatelessWidget {
  const _EmptyStateCard({
    required this.title,
    required this.message,
  });

  final String title;
  final String message;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(22),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 8),
            Text(message, style: Theme.of(context).textTheme.bodyMedium),
          ],
        ),
      ),
    );
  }
}

Color _severityColor(String severity) {
  switch (severity) {
    case 'Fatal':
      return const Color(0xFF7B0D13);
    case 'Serious':
      return const Color(0xFFB81620);
    default:
      return const Color(0xFF0A7B56);
  }
}

String _formatTimestamp(DateTime? value) {
  if (value == null) {
    return 'Just now';
  }

  final local = value.toLocal();
  return '${local.year}-${local.month.toString().padLeft(2, '0')}-${local.day.toString().padLeft(2, '0')} '
      '${local.hour.toString().padLeft(2, '0')}:${local.minute.toString().padLeft(2, '0')}';
}

String _aiSourceLabel(IncidentReport report) {
  switch (report.aiSource) {
    case 'python_model':
      return 'Photo AI model';
    case 'description_fallback':
      return 'Description fallback';
    case 'manual_override':
      return 'Manual override';
    default:
      return report.aiStatus.isEmpty ? 'Pending' : report.aiStatus;
  }
}

String _aiSourceChipLabel(IncidentReport report) {
  switch (report.aiSource) {
    case 'python_model':
      return 'PHOTO AI';
    case 'description_fallback':
      return 'FALLBACK';
    case 'manual_override':
      return 'MANUAL';
    default:
      return 'AI SOURCE';
  }
}

String _aiModelLabel(IncidentReport report) {
  if (report.aiModelName.isEmpty) {
    return 'Pending';
  }

  if (report.aiModelVersion.isEmpty) {
    return report.aiModelName;
  }

  return '${report.aiModelName} v${report.aiModelVersion}';
}

String _aiReviewStateLabel(IncidentReport report) {
  if (report.aiStatus == 'failed') {
    return 'AI unavailable';
  }

  if (report.aiReviewRequired) {
    return 'Responder review needed';
  }

  if (report.aiStatus == 'fallback') {
    return 'Fallback mode';
  }

  if (report.aiStatus == 'complete') {
    return 'Ready for triage';
  }

  return 'Pending AI review';
}

String _aiReviewChipLabel(IncidentReport report) {
  if (report.aiStatus == 'failed') {
    return 'AI OFFLINE';
  }

  if (report.aiReviewRequired) {
    return 'REVIEW NOW';
  }

  if (report.aiStatus == 'fallback') {
    return 'FALLBACK MODE';
  }

  if (report.aiStatus == 'complete') {
    return 'TRIAGE READY';
  }

  return 'AI PENDING';
}

Color _aiReviewColor(IncidentReport report) {
  if (report.aiStatus == 'failed' || report.aiReviewRequired) {
    return const Color(0xFFB81620);
  }

  if (report.aiStatus == 'fallback') {
    return const Color(0xFF8A5E00);
  }

  if (report.aiStatus == 'complete') {
    return const Color(0xFF0A7B56);
  }

  return const Color(0xFF5E6B77);
}

Color _aiConfidenceColor(int confidence) {
  if (confidence >= 85) {
    return const Color(0xFF0A7B56);
  }

  if (confidence >= 60) {
    return const Color(0xFF8A5E00);
  }

  if (confidence > 0) {
    return const Color(0xFFB81620);
  }

  return const Color(0xFF5E6B77);
}
