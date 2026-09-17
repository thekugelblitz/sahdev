class ChatMessage {
  final int id;
  final int sessionId;
  final String senderType; // 'client', 'admin', 'ai', 'system'
  final String senderName;
  final String text;
  final bool isStaff;
  final bool isAi;
  final bool isClient;
  final bool isSystem;
  final String? createdAt;
  final String timeFormat;

  ChatMessage({
    required this.id,
    required this.sessionId,
    required this.senderType,
    required this.senderName,
    required this.text,
    required this.isStaff,
    required this.isAi,
    required this.isClient,
    required this.isSystem,
    this.createdAt,
    required this.timeFormat,
  });

  factory ChatMessage.fromJson(Map<String, dynamic> json) {
    return ChatMessage(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      sessionId: json['session_id'] is int ? json['session_id'] : 0,
      senderType: json['sender_type'] ?? 'client',
      senderName: json['sender_name'] ?? '',
      text: json['text'] ?? '',
      isStaff: json['is_staff'] == true,
      isAi: json['is_ai'] == true,
      isClient: json['is_client'] == true,
      isSystem: json['is_system'] == true,
      createdAt: json['created_at']?.toString(),
      timeFormat: json['time_format'] ?? '',
    );
  }
}
