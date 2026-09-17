class ChatSession {
  final int id;
  final String uuid;
  final String title;
  final String status; // 'active', 'taken_over', 'closed', etc.
  final String summonStatus; // 'none', 'requested', 'handled'
  final int assignedAdminId;
  final bool isMyChat;
  final SessionClient client;
  final SessionSource source;
  final SessionTyping typing;
  final SessionLastMessage? lastMessage;
  final int unreadCount;
  final String? lastMessageAt;
  final String? createdAt;

  ChatSession({
    required this.id,
    required this.uuid,
    required this.title,
    required this.status,
    required this.summonStatus,
    required this.assignedAdminId,
    required this.isMyChat,
    required this.client,
    required this.source,
    required this.typing,
    this.lastMessage,
    required this.unreadCount,
    this.lastMessageAt,
    this.createdAt,
  });

  bool get isUrgentSummon => summonStatus == 'requested';
  bool get isTakenOver => status == 'taken_over';
  bool get isClosed => status == 'closed' || status == 'escalated_ticket';

  factory ChatSession.fromJson(Map<String, dynamic> json) {
    return ChatSession(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      uuid: json['uuid'] ?? '',
      title: json['title'] ?? 'Chat Session',
      status: json['status'] ?? 'active',
      summonStatus: json['summon_status'] ?? 'none',
      assignedAdminId: json['assigned_admin_id'] is int ? json['assigned_admin_id'] : 0,
      isMyChat: json['is_my_chat'] == true,
      client: SessionClient.fromJson(json['client'] ?? {}),
      source: SessionSource.fromJson(json['source'] ?? {}),
      typing: SessionTyping.fromJson(json['typing'] ?? {}),
      lastMessage: json['last_message'] != null ? SessionLastMessage.fromJson(json['last_message']) : null,
      unreadCount: json['unread_count'] is int ? json['unread_count'] : 0,
      lastMessageAt: json['last_message_at']?.toString(),
      createdAt: json['created_at']?.toString(),
    );
  }
}

class SessionClient {
  final int id;
  final String name;
  final String email;
  final bool isRegistered;

  SessionClient({
    required this.id,
    required this.name,
    required this.email,
    required this.isRegistered,
  });

  factory SessionClient.fromJson(Map<String, dynamic> json) {
    return SessionClient(
      id: json['id'] is int ? json['id'] : 0,
      name: json['name'] ?? 'Guest Visitor',
      email: json['email'] ?? '',
      isRegistered: json['is_registered'] == true,
    );
  }
}

class SessionSource {
  final String domain;
  final String page;
  final String? ip;

  SessionSource({
    required this.domain,
    required this.page,
    this.ip,
  });

  factory SessionSource.fromJson(Map<String, dynamic> json) {
    return SessionSource(
      domain: json['domain'] ?? 'WHMCS',
      page: json['page'] ?? '/',
      ip: json['ip']?.toString(),
    );
  }
}

class SessionTyping {
  final bool isTyping;
  final String preview;

  SessionTyping({
    required this.isTyping,
    required this.preview,
  });

  factory SessionTyping.fromJson(Map<String, dynamic> json) {
    return SessionTyping(
      isTyping: json['is_typing'] == true,
      preview: json['preview'] ?? '',
    );
  }
}

class SessionLastMessage {
  final int id;
  final String senderType;
  final String senderName;
  final String text;
  final String? createdAt;
  final String? timeAgo;

  SessionLastMessage({
    required this.id,
    required this.senderType,
    required this.senderName,
    required this.text,
    this.createdAt,
    this.timeAgo,
  });

  factory SessionLastMessage.fromJson(Map<String, dynamic> json) {
    return SessionLastMessage(
      id: json['id'] is int ? json['id'] : 0,
      senderType: json['sender_type'] ?? 'client',
      senderName: json['sender_name'] ?? '',
      text: json['text'] ?? '',
      createdAt: json['created_at']?.toString(),
      timeAgo: json['time_ago']?.toString(),
    );
  }
}
