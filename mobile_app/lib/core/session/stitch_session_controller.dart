import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../features/shared/models/stitch_models.dart';
import '../network/stitch_api_client.dart';
import '../network/stitch_api_exception.dart';

class StitchSessionController extends ChangeNotifier {
  StitchSessionController({
    required StitchApiClient apiClient,
    required SharedPreferences preferences,
  })  : _apiClient = apiClient,
        _preferences = preferences;

  static const _tokenStorageKey = 'stitch_api_token';

  final StitchApiClient _apiClient;
  final SharedPreferences _preferences;

  bool _initialized = false;
  String? _token;
  StitchUser? _currentUser;

  bool get isInitialized => _initialized;
  bool get isAuthenticated => _token != null && _currentUser != null;
  String? get authToken => _token;
  Map<String, String> get authHeaders => _token == null || _token!.isEmpty
      ? const <String, String>{}
      : <String, String>{'Authorization': 'Bearer ${_token!}'};
  StitchUser? get currentUser => _currentUser;

  Future<void> initialize() async {
    final savedToken = _preferences.getString(_tokenStorageKey);

    if (savedToken != null && savedToken.isNotEmpty) {
      try {
        final user = await _apiClient.me(savedToken);
        _token = savedToken;
        _currentUser = user;
      } on StitchApiException {
        await _clearSession(notifyListeners: false);
      }
    }

    _initialized = true;
    notifyListeners();
  }

  Future<void> login({
    required String email,
    required String password,
  }) async {
    final session = await _apiClient.login(
      email: email,
      password: password,
    );
    await _persistSession(session);
  }

  Future<void> register({
    required String name,
    required String phone,
    required String email,
    required String password,
    required String role,
  }) async {
    final session = await _apiClient.register(
      name: name,
      phone: phone,
      email: email,
      password: password,
      role: role,
    );
    await _persistSession(session);
  }

  Future<void> logout() async {
    final activeToken = _token;

    try {
      if (activeToken != null) {
        await _apiClient.logout(activeToken);
      }
    } on StitchApiException {
      // Local logout should still proceed even if the server token was already invalid.
    } finally {
      await _clearSession();
    }
  }

  Future<DashboardSnapshot> fetchDashboard() {
    return _withToken(_apiClient.fetchDashboard);
  }

  Future<List<IncidentReport>> fetchReports() {
    return _withToken(_apiClient.fetchReports);
  }

  Future<IncidentReport> createReport({
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
  }) {
    return _withToken(
      (token) => _apiClient.createReport(
        token: token,
        incidentType: incidentType,
        severity: severity,
        locationText: locationText,
        description: description,
        transmissionType: transmissionType,
        evidenceType: evidenceType,
        evidenceFile: evidenceFile,
        evidenceFilename: evidenceFilename,
        selfieFile: selfieFile,
        selfieFilename: selfieFilename,
        latitude: latitude,
        longitude: longitude,
      ),
    );
  }

  Future<StitchUser> fetchProfile() {
    return _withToken(_apiClient.fetchProfile);
  }

  Future<StitchUser> updateProfile({
    required String name,
    required String phone,
    required String station,
  }) async {
    final profile = await _withToken(
      (token) => _apiClient.updateProfile(
        token: token,
        name: name,
        phone: phone,
        station: station,
      ),
    );

    _currentUser = profile;
    notifyListeners();
    return profile;
  }

  Future<AppSettingsModel> fetchSettings() {
    return _withToken(_apiClient.fetchSettings);
  }

  Future<AppSettingsModel> updateSettings(AppSettingsModel settings) {
    return _withToken(
      (token) => _apiClient.updateSettings(
        token: token,
        settings: settings,
      ),
    );
  }

  Future<void> _persistSession(AuthSession session) async {
    _token = session.token;
    _currentUser = session.user;
    await _preferences.setString(_tokenStorageKey, session.token);
    notifyListeners();
  }

  Future<void> _clearSession({bool notifyListeners = true}) async {
    _token = null;
    _currentUser = null;
    await _preferences.remove(_tokenStorageKey);
    if (notifyListeners) {
      this.notifyListeners();
    }
  }

  Future<T> _withToken<T>(Future<T> Function(String token) action) async {
    final token = _token;

    if (token == null) {
      throw const StitchApiException(
        message: 'You are not logged in.',
        statusCode: 401,
      );
    }

    try {
      return await action(token);
    } on StitchApiException catch (error) {
      if (error.statusCode == 401) {
        await _clearSession();
      }
      rethrow;
    }
  }
}
