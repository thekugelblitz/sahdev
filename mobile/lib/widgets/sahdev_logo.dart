import 'dart:math' as math;
import 'package:flutter/material.dart';

/// Official Sahdev Logo Widget (Concept 8: The Cognitive Cortex Matrix)
/// Pixel-perfect, resolution-independent vector rendering via CustomPainter.
class SahdevLogo extends StatelessWidget {
  final double size;
  final bool withBackground;
  final double? borderRadius;
  final bool showGlow;

  const SahdevLogo({
    super.key,
    this.size = 64.0,
    this.withBackground = true,
    this.borderRadius,
    this.showGlow = true,
  });

  @override
  Widget build(BuildContext context) {
    final radius = borderRadius ?? (size * 0.22);

    Widget mark = SizedBox(
      width: size,
      height: size,
      child: CustomPaint(
        painter: _NeuralLatticePainter(showGlow: showGlow),
      ),
    );

    if (!withBackground) {
      return mark;
    }

    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: const Color(0xFF0F172A),
        borderRadius: BorderRadius.circular(radius),
        border: Border.all(
          color: const Color(0xFF134E4A).withOpacity(0.5),
          width: math.max(1.0, size * 0.015),
        ),
        boxShadow: showGlow
            ? [
                BoxShadow(
                  color: const Color(0xFF06B6D4).withOpacity(0.25),
                  blurRadius: size * 0.25,
                  offset: Offset(0, size * 0.08),
                ),
              ]
            : null,
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(radius),
        child: mark,
      ),
    );
  }
}

/// Horizontal Lockup for Headers / AppBars / Web Navbars
class SahdevHorizontalLogo extends StatelessWidget {
  final double height;
  final Color textColor;
  final String subtitle;

  const SahdevHorizontalLogo({
    super.key,
    this.height = 36.0,
    this.textColor = Colors.white,
    this.subtitle = "AI TICKET INTELLIGENCE",
  });

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        SahdevLogo(
          size: height,
          withBackground: true,
          showGlow: false,
        ),
        SizedBox(width: height * 0.3),
        Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              "SAHDEV",
              style: TextStyle(
                fontFamily: 'Inter',
                fontSize: height * 0.52,
                fontWeight: FontWeight.w800,
                color: textColor,
                letterSpacing: 1.2,
                height: 1.0,
              ),
            ),
            SizedBox(height: height * 0.08),
            Text(
              subtitle,
              style: TextStyle(
                fontFamily: 'Inter',
                fontSize: height * 0.22,
                fontWeight: FontWeight.w600,
                color: const Color(0xFF2DD4BF),
                letterSpacing: 2.0,
                height: 1.0,
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class _NeuralLatticePainter extends CustomPainter {
  final bool showGlow;

  _NeuralLatticePainter({required this.showGlow});

  @override
  void paint(Canvas canvas, Size size) {
    final scale = size.width / 512.0;
    canvas.save();
    canvas.scale(scale, scale);

    // Matrix Synaptic Lines forming 'S'
    final path = Path();
    path.moveTo(330, 150);
    path.lineTo(256, 110);
    path.lineTo(182, 150);
    path.lineTo(182, 230);
    path.lineTo(256, 270);
    path.lineTo(330, 310);
    path.lineTo(330, 390);
    path.lineTo(256, 430);
    path.lineTo(182, 390);

    final shader = const LinearGradient(
      begin: Alignment.topLeft,
      end: Alignment.bottomRight,
      colors: [
        Color(0xFF2DD4BF),
        Color(0xFF06B6D4),
        Color(0xFF3B82F6),
      ],
    ).createShader(const Rect.fromLTWH(182, 110, 148, 320));

    if (showGlow) {
      final glowPaint = Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 26
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round
        ..color = const Color(0xFF06B6D4).withOpacity(0.3)
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 12);
      canvas.drawPath(path, glowPaint);
    }

    final linePaint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 20
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..shader = shader;
    canvas.drawPath(path, linePaint);

    // Cross connectors (dashed lines)
    final connectorPaint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 3.5
      ..color = const Color(0xFF2DD4BF).withOpacity(0.5);

    _drawDashedLine(canvas, const Offset(256, 110), const Offset(256, 270), connectorPaint);
    _drawDashedLine(canvas, const Offset(256, 270), const Offset(256, 430), connectorPaint);

    // Synapse Nodes
    void drawNode(Offset center, double radius, Color color, [bool centerDot = false]) {
      if (showGlow) {
        canvas.drawCircle(
          center,
          radius + 4,
          Paint()
            ..color = color.withOpacity(0.35)
            ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 6),
        );
      }
      canvas.drawCircle(center, radius, Paint()..color = color);
      if (centerDot) {
        canvas.drawCircle(center, radius * 0.45, Paint()..color = const Color(0xFF06B6D4));
      }
    }

    drawNode(const Offset(330, 150), 14, const Color(0xFF2DD4BF));
    drawNode(const Offset(256, 110), 16, const Color(0xFF5EEAD4));
    drawNode(const Offset(182, 150), 14, const Color(0xFF2DD4BF));
    drawNode(const Offset(182, 230), 14, const Color(0xFF14B8A6));
    drawNode(const Offset(256, 270), 18, Colors.white, true);
    drawNode(const Offset(330, 310), 14, const Color(0xFF0EA5E9));
    drawNode(const Offset(330, 390), 14, const Color(0xFF0284C7));
    drawNode(const Offset(256, 430), 16, const Color(0xFF38BDF8));
    drawNode(const Offset(182, 390), 14, const Color(0xFF3B82F6));

    canvas.restore();
  }

  void _drawDashedLine(Canvas canvas, Offset p1, Offset p2, Paint paint) {
    const dashWidth = 6.0;
    const dashSpace = 6.0;
    final dx = p2.dx - p1.dx;
    final dy = p2.dy - p1.dy;
    final distance = math.sqrt(dx * dx + dy * dy);
    final count = (distance / (dashWidth + dashSpace)).floor();

    for (int i = 0; i < count; i++) {
      final startFraction = (i * (dashWidth + dashSpace)) / distance;
      final endFraction = ((i * (dashWidth + dashSpace)) + dashWidth) / distance;
      canvas.drawLine(
        Offset(p1.dx + dx * startFraction, p1.dy + dy * startFraction),
        Offset(p1.dx + dx * endFraction, p1.dy + dy * endFraction),
        paint,
      );
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
