import 'package:flutter/material.dart';

/// Reusable Shimmer animation controller widget.
class ShimmerLoading extends StatefulWidget {
  final Widget child;
  final Color? baseColor;
  final Color? highlightColor;

  const ShimmerLoading({
    super.key,
    required this.child,
    this.baseColor,
    this.highlightColor,
  });

  @override
  State<ShimmerLoading> createState() => _ShimmerLoadingState();
}

class _ShimmerLoadingState extends State<ShimmerLoading>
    with SingleTickerProviderStateMixin {
  late AnimationController _controller;
  late Animation<double> _animation;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1200),
    )..repeat();
    _animation = Tween<double>(begin: -1.0, end: 2.0).animate(
      CurvedAnimation(parent: _controller, curve: Curves.easeInOutSine),
    );
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final base =
        widget.baseColor ??
        (isDark ? const Color(0xFF1E293B) : const Color(0xFFE2E8F0));
    final highlight =
        widget.highlightColor ??
        (isDark ? const Color(0xFF334155) : const Color(0xFFF1F5F9));

    return AnimatedBuilder(
      animation: _animation,
      builder: (context, child) {
        return ShaderMask(
          blendMode: BlendMode.srcATop,
          shaderCallback: (bounds) {
            return LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              stops: [
                (_animation.value - 0.3).clamp(0.0, 1.0),
                _animation.value.clamp(0.0, 1.0),
                (_animation.value + 0.3).clamp(0.0, 1.0),
              ],
              colors: [base, highlight, base],
            ).createShader(bounds);
          },
          child: widget.child,
        );
      },
      child: widget.child,
    );
  }
}

/// Primitive skeleton shape blocks:
class SkeletonBox extends StatelessWidget {
  final double? width;
  final double? height;
  final double borderRadius;
  final EdgeInsetsGeometry? margin;

  const SkeletonBox({
    super.key,
    this.width,
    this.height,
    this.borderRadius = 8,
    this.margin,
  });

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Container(
      width: width,
      height: height,
      margin: margin,
      decoration: BoxDecoration(
        color: isDark ? const Color(0xFF1E293B) : const Color(0xFFE2E8F0),
        borderRadius: BorderRadius.circular(borderRadius),
      ),
    );
  }
}

class SkeletonCircle extends StatelessWidget {
  final double size;
  final EdgeInsetsGeometry? margin;

  const SkeletonCircle({super.key, this.size = 40, this.margin});

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Container(
      width: size,
      height: size,
      margin: margin,
      decoration: BoxDecoration(
        color: isDark ? const Color(0xFF1E293B) : const Color(0xFFE2E8F0),
        shape: BoxShape.circle,
      ),
    );
  }
}

/// Prebuilt Per-Data Skeleton Items:

/// 1. Skeleton List Tile (Digunakan pada Riwayat Pengajuan, Struk, Izin/Cuti, dll)
class SkeletonListTileItem extends StatelessWidget {
  const SkeletonListTileItem({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFF1F5F9)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.02),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: const Row(
        children: [
          SkeletonBox(width: 44, height: 44, borderRadius: 12),
          SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SkeletonBox(width: 120, height: 14, borderRadius: 4),
                SizedBox(height: 8),
                SkeletonBox(width: 160, height: 11, borderRadius: 4),
              ],
            ),
          ),
          SizedBox(width: 8),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              SkeletonBox(width: 54, height: 20, borderRadius: 6),
              SizedBox(height: 6),
              SkeletonBox(width: 40, height: 10, borderRadius: 4),
            ],
          ),
        ],
      ),
    );
  }
}

/// 2. Skeleton History Item (Khusus Riwayat Presensi dengan jam masuk/pulang, durasi, dll)
class SkeletonAttendanceItem extends StatelessWidget {
  const SkeletonAttendanceItem({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFF1F5F9)),
      ),
      child: const Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  SkeletonCircle(size: 32),
                  SizedBox(width: 10),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      SkeletonBox(width: 100, height: 14, borderRadius: 4),
                      SizedBox(height: 4),
                      SkeletonBox(width: 70, height: 10, borderRadius: 4),
                    ],
                  ),
                ],
              ),
              SkeletonBox(width: 60, height: 22, borderRadius: 8),
            ],
          ),
          SizedBox(height: 14),
          Row(
            children: [
              Expanded(child: SkeletonBox(height: 38, borderRadius: 10)),
              SizedBox(width: 8),
              Expanded(child: SkeletonBox(height: 38, borderRadius: 10)),
            ],
          ),
        ],
      ),
    );
  }
}

/// 3. Skeleton Shift Card (Khusus Kalender / Jadwal Shift)
class SkeletonShiftCard extends StatelessWidget {
  const SkeletonShiftCard({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFF1F5F9)),
      ),
      child: const Row(
        children: [
          SkeletonBox(width: 48, height: 48, borderRadius: 14),
          SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SkeletonBox(width: 130, height: 14, borderRadius: 4),
                SizedBox(height: 6),
                SkeletonBox(width: 90, height: 11, borderRadius: 4),
              ],
            ),
          ),
          SkeletonBox(width: 70, height: 24, borderRadius: 8),
        ],
      ),
    );
  }
}

/// 4. Skeleton Leave Card (Khusus Saldo / Pengajuan Izin Cuti)
class SkeletonLeaveCard extends StatelessWidget {
  const SkeletonLeaveCard({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFF1F5F9)),
      ),
      child: const Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              SkeletonBox(width: 80, height: 22, borderRadius: 6),
              SkeletonBox(width: 60, height: 20, borderRadius: 6),
            ],
          ),
          SizedBox(height: 12),
          SkeletonBox(width: 160, height: 13, borderRadius: 4),
          SizedBox(height: 8),
          SkeletonBox(width: 220, height: 11, borderRadius: 4),
        ],
      ),
    );
  }
}

/// 5. Skeleton Expense Report Card (Khusus Kartu Laporan Dinas di Tab Riwayat)
class SkeletonExpenseReportCard extends StatelessWidget {
  const SkeletonExpenseReportCard({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.grey.shade200),
      ),
      child: const Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  SkeletonBox(width: 16, height: 16, borderRadius: 4),
                  SizedBox(width: 6),
                  SkeletonBox(width: 110, height: 13, borderRadius: 4),
                ],
              ),
              SkeletonBox(width: 65, height: 22, borderRadius: 6),
            ],
          ),
          SizedBox(height: 10),
          SkeletonBox(width: 220, height: 15, borderRadius: 4),
          SizedBox(height: 8),
          Row(
            children: [
              SkeletonBox(width: 14, height: 14, borderRadius: 3),
              SizedBox(width: 6),
              SkeletonBox(width: 140, height: 12, borderRadius: 4),
              Spacer(),
              SkeletonBox(width: 55, height: 20, borderRadius: 4),
            ],
          ),
          Divider(height: 18),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              SkeletonBox(width: 100, height: 12, borderRadius: 4),
              SkeletonBox(width: 90, height: 15, borderRadius: 4),
            ],
          ),
        ],
      ),
    );
  }
}

/// 6. Skeleton Receipt Item Card (Khusus Kartu Struk Terlampir di Laporan Dinas)
class SkeletonReceiptItemCard extends StatelessWidget {
  const SkeletonReceiptItemCard({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: Colors.grey.shade200),
      ),
      child: const Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          SkeletonBox(width: 48, height: 48, borderRadius: 8),
          SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SkeletonBox(width: 75, height: 11, borderRadius: 3),
                SizedBox(height: 5),
                SkeletonBox(width: 130, height: 13, borderRadius: 4),
                SizedBox(height: 5),
                SkeletonBox(width: 160, height: 11, borderRadius: 3),
              ],
            ),
          ),
          SizedBox(width: 8),
          SkeletonBox(width: 75, height: 15, borderRadius: 4),
        ],
      ),
    );
  }
}

/// 7. Skeleton Expense Report Detail (Layar Penuh Rincian Laporan Dinas)
class SkeletonExpenseReportDetail extends StatelessWidget {
  const SkeletonExpenseReportDetail({super.key});

  @override
  Widget build(BuildContext context) {
    return ShimmerLoading(
      child: SingleChildScrollView(
        physics: const NeverScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Status banner shimmer
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: Colors.grey.shade200),
              ),
              child: const Row(
                children: [
                  SkeletonBox(width: 24, height: 24, borderRadius: 6),
                  SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        SkeletonBox(width: 140, height: 14, borderRadius: 4),
                        SizedBox(height: 6),
                        SkeletonBox(width: 220, height: 11, borderRadius: 4),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // Kartu Informasi Laporan
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: Colors.grey.shade200),
              ),
              child: const Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SkeletonBox(width: 200, height: 16, borderRadius: 4),
                  SizedBox(height: 8),
                  Row(
                    children: [
                      SkeletonBox(width: 15, height: 15, borderRadius: 4),
                      SizedBox(width: 6),
                      SkeletonBox(width: 140, height: 12, borderRadius: 4),
                    ],
                  ),
                  SizedBox(height: 12),
                  SkeletonBox(width: 260, height: 12, borderRadius: 4),
                  Divider(height: 24),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          SkeletonBox(width: 60, height: 10, borderRadius: 3),
                          SizedBox(height: 4),
                          SkeletonBox(width: 110, height: 16, borderRadius: 4),
                        ],
                      ),
                      SkeletonBox(width: 65, height: 24, borderRadius: 20),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 20),

            // Header Daftar Struk Terlampir
            const Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                SkeletonBox(width: 160, height: 15, borderRadius: 4),
                SkeletonBox(width: 80, height: 15, borderRadius: 4),
              ],
            ),
            const SizedBox(height: 12),

            // 4x Skeleton Struk Items
            const SkeletonReceiptItemCard(),
            const SkeletonReceiptItemCard(),
            const SkeletonReceiptItemCard(),
            const SkeletonReceiptItemCard(),
          ],
        ),
      ),
    );
  }
}

