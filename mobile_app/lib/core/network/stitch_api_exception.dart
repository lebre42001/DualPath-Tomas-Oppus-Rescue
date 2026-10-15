class StitchApiException implements Exception {
  const StitchApiException({
    required this.message,
    required this.statusCode,
  });

  final String message;
  final int statusCode;

  @override
  String toString() => message;
}
