class AdminUser {
  final int id;
  final String username;
  final String name;
  final String email;

  AdminUser({
    required this.id,
    required this.username,
    required this.name,
    required this.email,
  });

  factory AdminUser.fromJson(Map<String, dynamic> json) {
    return AdminUser(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      username: json['username'] ?? '',
      name: json['name'] ?? 'Staff Agent',
      email: json['email'] ?? '',
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'username': username,
      'name': name,
      'email': email,
    };
  }
}
