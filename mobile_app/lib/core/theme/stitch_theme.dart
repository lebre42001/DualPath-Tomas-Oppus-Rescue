import 'package:flutter/material.dart';

class StitchTheme {
  static ThemeData light() {
    const primary = Color(0xFFAF101A);
    const secondary = Color(0xFF005FAF);
    const surface = Color(0xFFF4FAFF);
    const ink = Color(0xFF0D1E25);

    final scheme = ColorScheme.fromSeed(
      brightness: Brightness.light,
      seedColor: primary,
      primary: primary,
      secondary: secondary,
      surface: surface,
      onSurface: ink,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: surface,
      appBarTheme: const AppBarTheme(
        centerTitle: false,
        elevation: 0,
        backgroundColor: Colors.transparent,
        foregroundColor: ink,
      ),
      cardTheme: CardThemeData(
        color: Colors.white.withValues(alpha: 0.92),
        shadowColor: ink.withValues(alpha: 0.08),
        surfaceTintColor: Colors.white,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(28),
          side: BorderSide(color: ink.withValues(alpha: 0.06)),
        ),
      ),
      navigationDrawerTheme: NavigationDrawerThemeData(
        backgroundColor: Colors.white,
        surfaceTintColor: Colors.white,
        tileHeight: 52,
        indicatorColor: primary.withValues(alpha: 0.10),
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          final color = states.contains(WidgetState.selected) ? primary : ink;
          return TextStyle(color: color, fontWeight: FontWeight.w700);
        }),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: Colors.white.withValues(alpha: 0.90),
        indicatorColor: primary.withValues(alpha: 0.12),
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          final color = states.contains(WidgetState.selected) ? primary : ink;
          return TextStyle(color: color, fontWeight: FontWeight.w700);
        }),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: primary,
          foregroundColor: Colors.white,
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(18),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: ink,
          side: BorderSide(color: ink.withValues(alpha: 0.10)),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(18),
          ),
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: secondary.withValues(alpha: 0.10),
        labelStyle: const TextStyle(
          color: secondary,
          fontWeight: FontWeight.w700,
        ),
        side: BorderSide.none,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
      ),
      textTheme: const TextTheme(
        displaySmall: TextStyle(
          fontSize: 36,
          fontWeight: FontWeight.w800,
          color: ink,
          height: 0.95,
        ),
        headlineMedium: TextStyle(
          fontSize: 24,
          fontWeight: FontWeight.w800,
          color: ink,
        ),
        titleLarge: TextStyle(
          fontSize: 20,
          fontWeight: FontWeight.w800,
          color: ink,
        ),
        bodyLarge: TextStyle(
          fontSize: 16,
          fontWeight: FontWeight.w500,
          color: ink,
          height: 1.6,
        ),
        bodyMedium: TextStyle(
          fontSize: 14,
          fontWeight: FontWeight.w500,
          color: Color(0xFF5A6B75),
          height: 1.6,
        ),
        labelLarge: TextStyle(
          fontSize: 14,
          fontWeight: FontWeight.w700,
          color: ink,
        ),
      ),
    );
  }
}
