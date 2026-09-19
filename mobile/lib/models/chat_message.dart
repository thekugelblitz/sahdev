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
  final bool isEdited;
  final bool isDeleted;
  final bool isSilent;
  final String? editedAt;

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
    this.isEdited = false,
    this.isDeleted = false,
    this.isSilent = false,
    this.editedAt,
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

    final isEdited = json['is_edited'] == true || json['is_edited'] == 1 || json['is_edited'] == '1';
    final isDeleted = json['is_deleted'] == true || json['is_deleted'] == 1 || json['is_deleted'] == '1';
    final isSilent = json['is_silent'] == true || json['is_silent'] == 1 || json['is_silent'] == '1';

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
      isEdited: isEdited,
      isDeleted: isDeleted,
      isSilent: isSilent,
      editedAt: json['edited_at']?.toString(),
    );
  }

  ChatMessage copyWith({
    int? id,
    bool? isSending,
    String? timeFormat,
    String? text,
    bool? isEdited,
    bool? isDeleted,
    bool? isSilent,
    String? editedAt,
  }) {
    return ChatMessage(
      id: id ?? this.id,
      sessionId: sessionId,
      senderType: senderType,
      senderName: senderName,
      text: text ?? this.text,
      isStaff: isStaff,
      isAi: isAi,
      isClient: isClient,
      isSystem: isSystem,
      createdAt: createdAt,
      timeFormat: timeFormat ?? this.timeFormat,
      isSending: isSending ?? this.isSending,
      isEdited: isEdited ?? this.isEdited,
      isDeleted: isDeleted ?? this.isDeleted,
      isSilent: isSilent ?? this.isSilent,
      editedAt: editedAt ?? this.editedAt,
    );
  }
}
