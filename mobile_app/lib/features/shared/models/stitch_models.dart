import '../../../core/config/stitch_api_config.dart';

class AuthSession {
  const AuthSession({
    required this.token,
    required this.user,
  });

  final String token;
  final StitchUser user;

  factory AuthSession.fromJson(Map<String, dynamic> json) {
    return AuthSession(
      token: _asString(json['token']),
      user: StitchUser.fromJson(_asMap(json['user'])),
    );
  }
}

class StitchUser {
  const StitchUser({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
    required this.isAdmin,
    required this.phone,
    required this.station,
    required this.connectivityMode,
    required this.notificationProfile,
  });

  final int id;
  final String name;
  final String email;
  final String role;
  final bool isAdmin;
  final String phone;
  final String station;
  final String connectivityMode;
  final List<String> notificationProfile;

  bool get isCivilian => role == 'civilian';
  bool get isResponder => role == 'responder';
  bool get isAdminResponder => isResponder && isAdmin;

  String get roleLabel {
    if (isAdminResponder) {
      return 'Admin Responder';
    }

    return isCivilian ? 'Civilian' : 'Responder';
  }

  factory StitchUser.fromJson(Map<String, dynamic> json) {
    return StitchUser(
      id: _asInt(json['id']),
      name: _asString(json['name']),
      email: _asString(json['email']),
      role: _asString(json['role']).isEmpty ? 'civilian' : _asString(json['role']),
      isAdmin: _asBool(json['is_admin']),
      phone: _asString(json['phone']),
      station: _asString(json['station']),
      connectivityMode: _asString(json['connectivity_mode']),
      notificationProfile: _asStringList(json['notification_profile']),
    );
  }
}

class DashboardSnapshot {
  const DashboardSnapshot({
    required this.application,
    required this.user,
    required this.summary,
    required this.recentReports,
    required this.mapPoints,
    required this.notifications,
  });

  final String application;
  final StitchUser user;
  final DashboardSummary summary;
  final List<IncidentReport> recentReports;
  final List<MapPointModel> mapPoints;
  final List<String> notifications;

  factory DashboardSnapshot.fromJson(Map<String, dynamic> json) {
    return DashboardSnapshot(
      application: _asString(json['application']),
      user: StitchUser.fromJson(_asMap(json['user'])),
      summary: DashboardSummary.fromJson(_asMap(json['summary'])),
      recentReports: _asList(json['recent_reports'])
          .map((item) => IncidentReport.fromJson(_asMap(item)))
          .toList(),
      mapPoints: _asList(json['map_points'])
          .map((item) => MapPointModel.fromJson(_asMap(item)))
          .toList(),
      notifications: _asStringList(json['notifications']),
    );
  }
}

class DashboardSummary {
  const DashboardSummary({
    required this.activeAlerts,
    required this.myReports,
    required this.transmittedToday,
    required this.assignedToMe,
    required this.fatalReports,
    required this.onlineReports,
    required this.loraReports,
  });

  final int activeAlerts;
  final int myReports;
  final int transmittedToday;
  final int assignedToMe;
  final int fatalReports;
  final int onlineReports;
  final int loraReports;

  factory DashboardSummary.fromJson(Map<String, dynamic> json) {
    return DashboardSummary(
      activeAlerts: _asInt(json['active_alerts']),
      myReports: _asInt(json['my_reports']),
      transmittedToday: _asInt(json['transmitted_today']),
      assignedToMe: _asInt(json['assigned_to_me']),
      fatalReports: _asInt(json['fatal_reports']),
      onlineReports: _asInt(json['online_reports']),
      loraReports: _asInt(json['lora_reports']),
    );
  }
}

class MapPointModel {
  const MapPointModel({
    required this.id,
    required this.label,
    required this.severity,
    required this.latitude,
    required this.longitude,
  });

  final int id;
  final String label;
  final String severity;
  final double latitude;
  final double longitude;

  factory MapPointModel.fromJson(Map<String, dynamic> json) {
    return MapPointModel(
      id: _asInt(json['id']),
      label: _asString(json['label']),
      severity: _asString(json['severity']),
      latitude: _asDouble(json['latitude']),
      longitude: _asDouble(json['longitude']),
    );
  }
}

class IncidentReport {
  const IncidentReport({
    required this.id,
    required this.reportCode,
    required this.reporterName,
    required this.reporterContact,
    required this.incidentType,
    required this.severity,
    required this.status,
    required this.channel,
    required this.transmissionType,
    required this.locationText,
    required this.latitude,
    required this.longitude,
    required this.description,
    required this.aiSummary,
    required this.aiConfidence,
    required this.aiSource,
    required this.aiStatus,
    required this.aiModelName,
    required this.aiModelVersion,
    required this.aiReviewRequired,
    required this.aiProbabilities,
    required this.aiProcessedAt,
    required this.aiErrorMessage,
    required this.evidenceType,
    required this.evidenceOriginalName,
    required this.evidenceAvailable,
    required this.evidenceUrl,
    required this.selfieOriginalName,
    required this.selfieAvailable,
    required this.selfieUrl,
    required this.selfieCapturedAt,
    required this.assignedResponderName,
    required this.responseNotes,
    required this.transmittedAt,
    required this.createdAt,
  });

  final int id;
  final String reportCode;
  final String reporterName;
  final String reporterContact;
  final String incidentType;
  final String severity;
  final String status;
  final String channel;
  final String transmissionType;
  final String locationText;
  final double? latitude;
  final double? longitude;
  final String description;
  final String aiSummary;
  final int aiConfidence;
  final String aiSource;
  final String aiStatus;
  final String aiModelName;
  final String aiModelVersion;
  final bool aiReviewRequired;
  final Map<String, double> aiProbabilities;
  final DateTime? aiProcessedAt;
  final String aiErrorMessage;
  final String evidenceType;
  final String evidenceOriginalName;
  final bool evidenceAvailable;
  final String evidenceUrl;
  final String selfieOriginalName;
  final bool selfieAvailable;
  final String selfieUrl;
  final DateTime? selfieCapturedAt;
  final String assignedResponderName;
  final String responseNotes;
  final DateTime? transmittedAt;
  final DateTime? createdAt;

  bool get hasGps => latitude != null && longitude != null;
  bool get isOnline => transmissionType == 'online';
  bool get isLoRa => transmissionType == 'lora';
  bool get hasPhotoEvidence => evidenceAvailable && evidenceType == 'photo';
  bool get hasVideoEvidence => evidenceAvailable && evidenceType == 'video';

  factory IncidentReport.fromJson(Map<String, dynamic> json) {
    return IncidentReport(
      id: _asInt(json['id']),
      reportCode: _asString(json['report_code']),
      reporterName: _asString(json['reporter_name']),
      reporterContact: _asString(json['reporter_contact']),
      incidentType: _asString(json['incident_type']),
      severity: _asString(json['severity']),
      status: _asString(json['status']),
      channel: _asString(json['channel']),
      transmissionType: _asString(json['transmission_type']),
      locationText: _asString(json['location_text']),
      latitude: _asNullableDouble(json['latitude']),
      longitude: _asNullableDouble(json['longitude']),
      description: _asString(json['description']),
      aiSummary: _asString(json['ai_summary']),
      aiConfidence: _asInt(json['ai_confidence']),
      aiSource: _asString(json['ai_source']),
      aiStatus: _asString(json['ai_status']),
      aiModelName: _asString(json['ai_model_name']),
      aiModelVersion: _asString(json['ai_model_version']),
      aiReviewRequired: _asBool(json['ai_review_required']),
      aiProbabilities: _asProbabilityMap(json['ai_probabilities']),
      aiProcessedAt: _parseDate(json['ai_processed_at']),
      aiErrorMessage: _asString(json['ai_error_message']),
      evidenceType: _asString(json['evidence_type']),
      evidenceOriginalName: _asString(json['evidence_original_name']),
      evidenceAvailable: _asBool(json['evidence_available']),
      evidenceUrl: _normalizeApiMediaUrl(_asString(json['evidence_url'])),
      selfieOriginalName: _asString(json['selfie_original_name']),
      selfieAvailable: _asBool(json['selfie_available']),
      selfieUrl: _normalizeApiMediaUrl(_asString(json['selfie_url'])),
      selfieCapturedAt: _parseDate(json['selfie_captured_at']),
      assignedResponderName: _asString(json['assigned_responder_name']),
      responseNotes: _asString(json['response_notes']),
      transmittedAt: _parseDate(json['transmitted_at']),
      createdAt: _parseDate(json['created_at']),
    );
  }
}

class AppSettingsModel {
  const AppSettingsModel({
    required this.criticalAlerts,
    required this.pushNotifications,
    required this.smsBackup,
    required this.connectivityMode,
    required this.storage,
  });

  final bool criticalAlerts;
  final bool pushNotifications;
  final bool smsBackup;
  final String connectivityMode;
  final String storage;

  factory AppSettingsModel.fromJson(Map<String, dynamic> json) {
    return AppSettingsModel(
      criticalAlerts: _asBool(json['critical_alerts']),
      pushNotifications: _asBool(json['push_notifications']),
      smsBackup: _asBool(json['sms_backup']),
      connectivityMode: _asString(json['connectivity_mode']),
      storage: _asString(json['storage']),
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'critical_alerts': criticalAlerts,
      'push_notifications': pushNotifications,
      'sms_backup': smsBackup,
      'connectivity_mode': connectivityMode,
    };
  }
}

Map<String, dynamic> _asMap(dynamic value) {
  if (value is Map<String, dynamic>) {
    return value;
  }

  if (value is Map) {
    return value.map((key, item) => MapEntry(key.toString(), item));
  }

  return const <String, dynamic>{};
}

List<dynamic> _asList(dynamic value) {
  if (value is List<dynamic>) {
    return value;
  }

  if (value is List) {
    return value.cast<dynamic>();
  }

  return const <dynamic>[];
}

String _asString(dynamic value) {
  return value?.toString() ?? '';
}

int _asInt(dynamic value) {
  if (value is int) {
    return value;
  }

  return int.tryParse(_asString(value)) ?? 0;
}

bool _asBool(dynamic value) {
  if (value is bool) {
    return value;
  }

  final normalized = _asString(value).toLowerCase();
  return normalized == 'true' || normalized == '1';
}

double _asDouble(dynamic value) {
  if (value is num) {
    return value.toDouble();
  }

  return double.tryParse(_asString(value)) ?? 0;
}

double? _asNullableDouble(dynamic value) {
  if (value == null || _asString(value).isEmpty) {
    return null;
  }

  return _asDouble(value);
}

List<String> _asStringList(dynamic value) {
  if (value is String) {
    return value
        .split(',')
        .map((item) => item.trim())
        .where((item) => item.isNotEmpty)
        .toList();
  }

  if (value is List) {
    return value.map((item) => item.toString()).toList();
  }

  return const <String>[];
}

Map<String, double> _asProbabilityMap(dynamic value) {
  final source = _asMap(value);
  return {
    'minor': _asDouble(source['minor']),
    'serious': _asDouble(source['serious']),
    'fatal': _asDouble(source['fatal']),
  };
}

DateTime? _parseDate(dynamic value) {
  if (value == null || value == '') {
    return null;
  }

  return DateTime.tryParse(value.toString());
}

String _normalizeApiMediaUrl(String value) {
  if (value.isEmpty) {
    return '';
  }

  final mediaUri = Uri.tryParse(value);
  final baseUri = Uri.tryParse(StitchApiConfig.baseUrl);

  if (mediaUri == null || baseUri == null) {
    return value;
  }

  if (!mediaUri.hasAuthority) {
    return baseUri.resolveUri(mediaUri).toString();
  }

  final normalizedUri = mediaUri.replace(
    scheme: baseUri.scheme,
    host: baseUri.host,
    port: baseUri.hasPort ? baseUri.port : mediaUri.port,
  );

  return normalizedUri.toString();
}
