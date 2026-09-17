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
  final bool isSending;

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
    this.isSending = false,
  });

  factory ChatMessage.fromJson(Map<String, dynamic> json) {
    final senderType = (json['sender_type'] ?? 'client').toString().toLowerCase();
    final isStaff = json['is_staff'] == true || senderType == 'admin' || senderType == 'staff';
    final isAi = json['is_ai'] == true || senderType == 'ai' || senderType == 'assistant';
    final isClient = json['is_client'] == true || senderType == 'client' || senderType == 'user';
    final isSystem = json['is_system'] == true || senderType == 'system' || senderType == 'event';

    var name = (json['sender_name'] ?? '').toString();
    if (name.isEmpty) {
      if (isStaff) {
        name = 'Staff Specialist';
      } else if (isAi) {
        name = 'Sahdev AI';
      } else if (isClient) {
        name = 'Visitor';
      } else {
        name = 'System';
      }
    }

    return ChatMessage(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      sessionId: json['session_id'] is int ? json['session_id'] : int.tryParse(json['session_id'].toString()) ?? 0,
      senderType: senderType,
      senderName: name,
      text: (json['text'] ?? '').toString(),
      isStaff: isStaff,
      isAi: isAi,
      isClient: isClient,
      isSystem: isSystem,
      createdAt: json['created_at']?.toString(),
      timeFormat: (json['time_format'] ?? '').toString(),
      isSending: json['is_sending'] == true,
    );
  }

  ChatMessage copyWith({
    int? id,
    bool? isSending,
    String? timeFormat,
  }) {
    return ChatMessage(
      id: id ?? this.id,
      sessionId: sessionId,
      senderType: senderType,
      senderName: senderName,
      text: text,
      isStaff: isStaff,
      isAi: isAi,
      isClient: isClient,
      isSystem: isSystem,
      createdAt: createdAt,
      timeFormat: timeFormat ?? this.timeFormat,
      isSending: isSending ?? this.isSending,
    );
  }
}
