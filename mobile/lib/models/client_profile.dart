class ClientProfile {
  final bool isRegistered;
  final ClientDetails client;
  final ClientSummary summary;
  final List<ClientHostingService> services;
  final List<ClientInvoice> invoices;
  final Map<String, dynamic>? visitorMeta;

  ClientProfile({
    required this.isRegistered,
    required this.client,
    required this.summary,
    required this.services,
    required this.invoices,
    this.visitorMeta,
  });

  factory ClientProfile.fromJson(Map<String, dynamic> json) {
    var sList = <ClientHostingService>[];
    if (json['services'] is List) {
      for (var item in json['services']) {
        sList.add(ClientHostingService.fromJson(item));
      }
    }

    var iList = <ClientInvoice>[];
    if (json['invoices'] is List) {
      for (var item in json['invoices']) {
        iList.add(ClientInvoice.fromJson(item));
      }
    }

    return ClientProfile(
      isRegistered: json['is_registered'] == true,
      client: ClientDetails.fromJson(json['client'] ?? {}),
      summary: ClientSummary.fromJson(json['summary'] ?? {}),
      services: sList,
      invoices: iList,
      visitorMeta: json['visitor_meta'] is Map ? Map<String, dynamic>.from(json['visitor_meta']) : null,
    );
  }
}

class ClientDetails {
  final int id;
  final String name;
  final String company;
  final String email;
  final String status;
  final String createdAt;

  ClientDetails({
    required this.id,
    required this.name,
    required this.company,
    required this.email,
    required this.status,
    required this.createdAt,
  });

  factory ClientDetails.fromJson(Map<String, dynamic> json) {
    return ClientDetails(
      id: json['id'] is int ? json['id'] : 0,
      name: json['name'] ?? 'Visitor',
      company: json['company'] ?? '',
      email: json['email'] ?? '',
      status: json['status'] ?? 'Active',
      createdAt: json['created_at']?.toString() ?? '',
    );
  }
}

class ClientSummary {
  final int servicesCount;
  final int unpaidInvoices;
  final String unpaidTotal;
  final int openTicketsCount;

  ClientSummary({
    required this.servicesCount,
    required this.unpaidInvoices,
    required this.unpaidTotal,
    required this.openTicketsCount,
  });

  factory ClientSummary.fromJson(Map<String, dynamic> json) {
    return ClientSummary(
      servicesCount: json['services_count'] is int ? json['services_count'] : 0,
      unpaidInvoices: json['unpaid_invoices'] is int ? json['unpaid_invoices'] : 0,
      unpaidTotal: json['unpaid_total']?.toString() ?? '0.00',
      openTicketsCount: json['open_tickets_count'] is int ? json['open_tickets_count'] : 0,
    );
  }
}

class ClientHostingService {
  final int id;
  final String domain;
  final String status;
  final String billingCycle;
  final String nextDueDate;
  final String productName;

  ClientHostingService({
    required this.id,
    required this.domain,
    required this.status,
    required this.billingCycle,
    required this.nextDueDate,
    required this.productName,
  });

  factory ClientHostingService.fromJson(Map<String, dynamic> json) {
    return ClientHostingService(
      id: json['id'] is int ? json['id'] : 0,
      domain: json['domain'] ?? '',
      status: json['domainstatus'] ?? '',
      billingCycle: json['billingcycle'] ?? '',
      nextDueDate: json['nextduedate']?.toString() ?? '',
      productName: json['product_name'] ?? 'Hosting Package',
    );
  }
}

class ClientInvoice {
  final int id;
  final String invoiceNum;
  final String total;
  final String dueDate;

  ClientInvoice({
    required this.id,
    required this.invoiceNum,
    required this.total,
    required this.dueDate,
  });

  factory ClientInvoice.fromJson(Map<String, dynamic> json) {
    return ClientInvoice(
      id: json['id'] is int ? json['id'] : 0,
      invoiceNum: json['invoicenum']?.toString() ?? json['id']?.toString() ?? '',
      total: json['total']?.toString() ?? '0.00',
      dueDate: json['duedate']?.toString() ?? '',
    );
  }
}
