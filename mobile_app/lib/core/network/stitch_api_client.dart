import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../../features/shared/models/stitch_models.dart';
import '../config/stitch_api_config.dart';
import 'stitch_api_exception.dart';

class StitchApiClient {
  StitchApiClient({http.Client? client}) : _client = client ?? http.Client();

  final http.Client _client;

  Future<AuthSession> login({
    required String email,
    required String password,
  }) async {
    final json = await _request(
      'POST',
      '/auth/login',
      body: {
        'email': email,
        'password': password,
      },
    );

    return AuthSession.fromJson(json);
  }

  Future<AuthSession> register({
    required String name,
    required String phone,
    required String email,
    required String password,
    required String role,
  }) async {
    final json = await _request(
      'POST',
      '/auth/register',
      body: {
        'name': name,
        'phone': phone,
        'email': email,
        'password': password,
        'password_confirmation': password,
        'role': role,
      },
    );

    return AuthSession.fromJson(json);
  }

  Future<void> logout(String token) async {
    await _request(
      'POST',
      '/auth/logout',
      token: token,
    );
  }

  Future<StitchUser> me(String token) async {
    final json = await _request(
      'GET',
      '/auth/me',
      token: token,
    );

    return StitchUser.fromJson(_asMap(json['user']));
  }

  Future<DashboardSnapshot> fetchDashboard(String token) async {
    final json = await _request(
      'GET',
      '/summary',
      token: token,
    );

    return DashboardSnapshot.fromJson(json);
  }

  Future<List<IncidentReport>> fetchReports(String token) async {
    final json = await _request(
      'GET',
      '/reports',
      token: token,
    );

    final data = (json['data'] as List<dynamic>? ?? const <dynamic>[]);
    return data
        .map((item) => IncidentReport.fromJson((item as Map).cast<String, dynamic>()))
        .toList();
  }

  Future<IncidentReport> createReport({
    required String token,
    required String incidentType,
    required String severity,
    required String locationText,
    required String description,
    required String transmissionType,
    required String evidenceType,
    File? evidenceFile,
    String? evidenceFilename,
    File? selfieFile,
    String? selfieFilename,
    double? latitude,
    double? longitude,
  }) async {
    try {
      final uri = Uri.parse('${StitchApiConfig.baseUrl}/reports');
      final request = http.MultipartRequest('POST', uri)
        ..headers.addAll({
          'Accept': 'application/json',
          'Authorization': 'Bearer $token',
        })
        ..fields['incident_type'] = incidentType
        ..fields['location_text'] = locationText
        ..fields['description'] = description
        ..fields['transmission_type'] = transmissionType
        ..fields['evidence_type'] = evidenceType;

      if (severity.isNotEmpty) {
        request.fields['severity'] = severity;
      }
      if (latitude != null) {
        request.fields['latitude'] = latitude.toString();
      }
      if (longitude != null) {
        request.fields['longitude'] = longitude.toString();
      }
      if (evidenceFile != null) {
        request.files.add(await http.MultipartFile.fromPath(
          'evidence',
          evidenceFile.path,
          filename: evidenceFilename,
        ));
      }
      if (selfieFile != null) {
        request.files.add(await http.MultipartFile.fromPath(
          'selfie',
          selfieFile.path,
          filename: selfieFilename,
        ));
      }

      final streamedResponse = await _client.send(request);
      final response = await http.Response.fromStream(streamedResponse);
      final payload = _decodePayload(response);

      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw StitchApiException(
          message: payload['message']?.toString() ?? 'Request failed.',
          statusCode: response.statusCode,
        );
      }

      return IncidentReport.fromJson(_asMap(payload['data']));
    } on StitchApiException {
      rethrow;
    } catch (_) {
      throw _unreachableApiException();
    }
  }

  Future<StitchUser> fetchProfile(String token) async {
    final json = await _request(
      'GET',
      '/profile',
      token: token,
    );

    return StitchUser.fromJson((json['data'] as Map).cast<String, dynamic>());
  }

  Future<StitchUser> updateProfile({
    required String token,
    required String name,
    required String phone,
    required String station,
  }) async {
    final json = await _request(
      'PUT',
      '/profile',
      token: token,
      body: {
        'name': name,
        'phone': phone,
        'station': station,
      },
    );

    return StitchUser.fromJson((json['data'] as Map).cast<String, dynamic>());
  }

  Future<AppSettingsModel> fetchSettings(String token) async {
    final json = await _request(
      'GET',
      '/settings',
      token: token,
    );

    return AppSettingsModel.fromJson((json['data'] as Map).cast<String, dynamic>());
  }

  Future<AppSettingsModel> updateSettings({
    required String token,
    required AppSettingsModel settings,
  }) async {
    final json = await _request(
      'PUT',
      '/settings',
      token: token,
      body: settings.toJson(),
    );

    return AppSettingsModel.fromJson((json['data'] as Map).cast<String, dynamic>());
  }

  Future<Map<String, dynamic>> _request(
    String method,
    String path, {
    String? token,
    Map<String, dynamic>? body,
  }) async {
    try {
      final uri = Uri.parse('${StitchApiConfig.baseUrl}$path');
      final headers = <String, String>{
        'Accept': 'application/json',
      };

      if (token != null && token.isNotEmpty) {
        headers['Authorization'] = 'Bearer $token';
      }

      http.Response response;
      switch (method) {
        case 'POST':
          headers['Content-Type'] = 'application/json';
          response = await _client.post(
            uri,
            headers: headers,
            body: jsonEncode(body ?? const <String, dynamic>{}),
          );
          break;
        case 'PUT':
          headers['Content-Type'] = 'application/json';
          response = await _client.put(
            uri,
            headers: headers,
            body: jsonEncode(body ?? const <String, dynamic>{}),
          );
          break;
        default:
          response = await _client.get(uri, headers: headers);
          break;
      }

      final payload = _decodePayload(response);

      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw StitchApiException(
          message: payload['message']?.toString() ?? 'Request failed.',
          statusCode: response.statusCode,
        );
      }

      return payload;
    } on StitchApiException {
      rethrow;
    } catch (_) {
      throw _unreachableApiException();
    }
  }

  Map<String, dynamic> _decodePayload(http.Response response) {
    return response.body.isEmpty
        ? <String, dynamic>{}
        : (jsonDecode(response.body) as Map).cast<String, dynamic>();
  }

  StitchApiException _unreachableApiException() {
    final baseUrl = StitchApiConfig.baseUrl;
    final androidHint = baseUrl.contains('10.0.2.2')
        ? ' If you are using a physical phone, rerun with STITCH_API_BASE_URL pointed at your PC LAN IP or use tool/run_stitch.ps1.'
        : '';

    return StitchApiException(
      message: 'Unable to reach the Stitch API. Confirm that Laravel is running at $baseUrl.$androidHint',
      statusCode: 0,
    );
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