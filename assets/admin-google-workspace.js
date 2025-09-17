jQuery(document).ready(function ($) {
	// ===================
	// Google Workspace API Handlers
	// ===================

	// Save Google Config - cho phép lưu từng phần
	$("#save-google-config").on("click", function (e) {
		e.preventDefault();

		var clientId = $("#google_client_id").val().trim();
		var clientSecret = $("#google_client_secret").val().trim();
		var userEmail = $("#google_user_email").val().trim();

		var button = $(this);
		var status = $("#google-token-status");

		button.prop("disabled", true).text("Saving...");

		// Hiển thị thông tin sẽ được lưu
		var fieldsToSave = [];
		if (clientId) fieldsToSave.push("Client ID");
		if (clientSecret) fieldsToSave.push("Client Secret");
		if (userEmail) fieldsToSave.push("User Email");

		if (fieldsToSave.length === 0) {
			status.text("⚠️ Không có thông tin nào để lưu!").css("color", "#856404");
			button.prop("disabled", false).text("Save Config");
			return;
		}

		status
			.text("💾 Đang lưu: " + fieldsToSave.join(", ") + "...")
			.css("color", "#666");

		$.ajax({
			url: mmi_ajax.ajax_url,
			method: "POST",
			data: {
				action: "mmi_save_google_config",
				security: mmi_ajax.nonce,
				client_id: clientId,
				client_secret: clientSecret,
				user_email: userEmail,
			},
			success: function (response) {
				if (response.success) {
					status
						.html(
							"✅ " +
								response.data.message +
								"<br><small style='color: #666;'>Refresh Token sẽ được lưu tự động từ OAuth callback</small>"
						)
						.css("color", "#46b450");

					// Hiển thị thông tin đã lưu
					console.log("Google config saved:", response.data.config);
				} else {
					status
						.text("❌ Failed to save: " + response.data)
						.css("color", "#dc3232");
				}
			},
			error: function (xhr, status, error) {
				status.text("❌ Save error: " + error).css("color", "#dc3232");
			},
			complete: function () {
				button.prop("disabled", false).text("Save Config");
			},
		});
	});

	// Generate Google Auth URL
	$("#generate-google-auth-url").on("click", function (e) {
		e.preventDefault();

		var clientId = $("#google_client_id").val().trim();
		var userEmail = $("#google_user_email").val().trim();

		if (!clientId) {
			alert("Vui lòng nhập Google Client ID trước!");
			$("#google_client_id").focus();
			return;
		}

		if (!userEmail) {
			alert("Vui lòng nhập Gmail/Workspace Email trước!");
			$("#google_user_email").focus();
			return;
		}

		var redirectUri =
			mmi_ajax.home_url + "/wp-admin/admin-ajax.php?action=mmi_google_callback";
		var scope = "https://www.googleapis.com/auth/gmail.readonly";
		var authUrl =
			"https://accounts.google.com/o/oauth2/auth?" +
			"client_id=" +
			encodeURIComponent(clientId) +
			"&redirect_uri=" +
			encodeURIComponent(redirectUri) +
			"&scope=" +
			encodeURIComponent(scope) +
			"&response_type=code" +
			"&access_type=offline" +
			"&prompt=consent"; // Force consent to ensure refresh token

		$("#generated-google-auth-url").val(authUrl);
		$("#google-auth-url-section").show();

		// Update copy button handlers for Google
		$("#copy-google-auth-url")
			.off("click")
			.on("click", function () {
				copyToClipboard(authUrl, $(this));
			});

		// Open URL handler
		$("#open-google-auth-url")
			.off("click")
			.on("click", function () {
				window.open(authUrl, "_blank");
			});
	});

	// Fetch Failed Delivery Emails from Google
	$("#fetch-google-failed-emails").on("click", function () {
		var button = $(this);
		var resultDiv = $("#google-failed-emails-result");

		// Get search query from select
		var searchType = $("#google-search-type").val();
		var searchQuery = searchType;

		button.prop("disabled", true).text("Fetching...");
		resultDiv.html(
			'<p style="color: #666;">🔍 Đang tìm kiếm email với query: ' +
				searchQuery +
				"...</p>"
		);

		$.post(
			mmi_ajax.ajax_url,
			{
				action: "mmi_google_fetch_failed_emails",
				security: mmi_ajax.nonce,
				search_query: searchQuery,
			},
			function (response) {
				if (response.success && response.data) {
					var data = response.data;
					var messages = data.messages || [];

					resultDiv.html('<div id="google-emails-container"></div>');

					if (messages.length > 0) {
						// displayGoogleFailedEmails(messages, data);

						// chạy vòng lặp để lấy chi tiết từng email, vì API trả về rất ít thông tin
						$.each(messages, function (index, message) {
							$.post(
								mmi_ajax.ajax_url,
								{
									action: "mmi_google_fetch_failed_emails",
									security: mmi_ajax.nonce,
									message_id: message.id,
								},
								function (response) {
									console.log(response);
									if (response.success && response.data) {
										var detailed_messages = data.detailed_messages || [];
									} else {
										// hiển thị lỗi nếu có
									}
								}
							);
						});

						//
						var statusMsg =
							"✅ Tìm thấy " +
							data.total_found +
							" email (hiển thị " +
							messages.length +
							" email chi tiết)" +
							" với query: " +
							searchQuery +
							" trong Gmail của " +
							data.user_email;
						resultDiv.prepend(
							'<p style="color: #46b450; background: #f0f8ff; padding: 10px; border-radius: 4px;">' +
								statusMsg +
								"</p>"
						);
					} else {
						resultDiv.html(
							'<p style="color: #dc3232;">❌ Không tìm thấy email nào với query: ' +
								searchQuery +
								"</p>"
						);
					}

					// Display token info
					if (data.token_info) {
						updateGoogleTokenCacheInfo(data.token_info);
					}
				} else {
					var errorMsg =
						response.data ||
						"Không tìm thấy email nào với query: " + searchQuery;
					resultDiv.html('<p style="color: #dc3232;">❌ ' + errorMsg + "</p>");
				}
			}
		)
			.fail(function (xhr, status, error) {
				resultDiv.html(
					'<p style="color: #dc3232;">❌ Lỗi kết nối: ' + error + "</p>"
				);
			})
			.always(function () {
				button.prop("disabled", false).text("🔍 Fetch Failed Emails");
			});
	});

	// Clear Google Token Cache
	$("#clear-google-token-cache").on("click", function () {
		var button = $(this);

		button.prop("disabled", true).text("Clearing...");

		$.post(
			mmi_ajax.ajax_url,
			{
				action: "mmi_clear_google_token_cache",
				security: mmi_ajax.nonce,
			},
			function (response) {
				if (response.success) {
					$("#google-token-status")
						.text("✅ " + response.data.message)
						.css("color", "#46b450");
				} else {
					$("#google-token-status")
						.text("❌ " + response.data)
						.css("color", "#dc3232");
				}
			}
		)
			.fail(function () {
				$("#google-token-status")
					.text("❌ Lỗi kết nối server.")
					.css("color", "#dc3232");
			})
			.always(function () {
				button.prop("disabled", false).text("Clear Token Cache");
			});
	});

	// Get Google Token Cache Info
	$("#google-token-cache-info").on("click", function () {
		var button = $(this);

		button.prop("disabled", true).text("Checking...");

		$.post(
			mmi_ajax.ajax_url,
			{
				action: "mmi_get_google_token_cache_info",
				security: mmi_ajax.nonce,
			},
			function (response) {
				if (response.success) {
					var data = response.data;
					var status = data.cache_exists
						? "✅ Cache exists (" + data.cache_timeout + ")"
						: "⚠️ No token cache";

					$("#google-token-status")
						.html(
							status +
								"<br><small>Token preview: " +
								(data.token_preview || "N/A") +
								"</small>"
						)
						.css("color", data.cache_exists ? "#46b450" : "#856404");
				} else {
					$("#google-token-status")
						.text("❌ " + response.data)
						.css("color", "#dc3232");
				}
			}
		)
			.fail(function () {
				$("#google-token-status")
					.text("❌ Lỗi kết nối server.")
					.css("color", "#dc3232");
			})
			.always(function () {
				button.prop("disabled", false).text("Token Cache Info");
			});
	});

	// Helper functions for Google Workspace API

	function displayGoogleFailedEmails(messages, data) {
		// var $ = jQuery;
		var container = $("#google-emails-container");
		if (messages.length === 0) {
			container.html("<p>No failed emails found.</p>");
			return;
		}

		var html = '<div style="margin: 15px 0;">';
		html +=
			"<h4>📧 Failed Delivery Emails from Gmail (" + data.user_email + ")</h4>";
		html +=
			'<div style="background: #f9f9f9; padding: 10px; border-radius: 4px; margin-bottom: 15px;">';
		html += "<strong>Search Query:</strong> " + data.search_query + "<br>";
		html +=
			"<strong>Total Found:</strong> " +
			data.total_found +
			" (showing " +
			messages.length +
			" detailed)<br>";
		html += "<strong>Gmail Account:</strong> " + data.user_email;
		html += "</div>";

		var failedEmails = [];
		var emailsHtml =
			'<div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">';

		messages.forEach(function (message, index) {
			try {
				var headers = message.payload.headers || [];
				var subject = "";
				var from = "";
				var to = "";

				headers.forEach(function (header) {
					if (header.name.toLowerCase() === "subject") {
						subject = header.value;
					} else if (header.name.toLowerCase() === "from") {
						from = header.value;
					} else if (header.name.toLowerCase() === "to") {
						to = header.value;
					}
				});

				emailsHtml +=
					'<div style="padding: 10px; border-bottom: 1px solid #eee; ' +
					(index % 2 === 0 ? "background: #fafafa;" : "") +
					'">';
				emailsHtml +=
					"<strong>Subject:</strong> " + escapeHtml(subject) + "<br>";
				emailsHtml += "<strong>From:</strong> " + escapeHtml(from) + "<br>";
				emailsHtml += "<strong>To:</strong> " + escapeHtml(to) + "<br>";
				if (typeof message.snippet != "undefined") {
					// Extract failed email addresses from subject or body
					var failedEmail = extractEmailFromGmailMessage(message.snippet);
					if (failedEmail) {
						failedEmails.push(failedEmail);

						emailsHtml +=
							'<strong style="color: #dc3232;">Failed Email:</strong> ' +
							escapeHtml(failedEmail) +
							"<br>";
					}

					emailsHtml +=
						"<strong>Snippet:</strong> " + escapeHtml(message.snippet) + "<br>";
				}
				emailsHtml +=
					'<small style="color: #666;">ID: ' + message.id + "</small>";
				emailsHtml += "</div>";
			} catch (e) {
				console.error("Error processing Gmail message:", e, message);
			}
		});

		emailsHtml += "</div>";
		html += emailsHtml;

		// Add bulk unsubscribe section
		if (failedEmails.length > 0) {
			var uniqueEmails = [...new Set(failedEmails)];
			html +=
				'<div style="margin-top: 20px; background: #fff3cd; padding: 15px; border-radius: 4px; border-left: 4px solid #ffc107;">';
			html +=
				"<h4>📋 Bulk Unsubscribe (" +
				uniqueEmails.length +
				" unique failed emails)</h4>";
			html +=
				'<textarea id="google-failed-emails-list" rows="5" style="width: 100%; margin-bottom: 10px;" readonly>';
			html += uniqueEmails.join(",");
			html += "</textarea>";
			html += "<div>";
			html +=
				'<button type="button" class="button button-secondary" onclick="copyToClipboard($(\'#google-failed-emails-list\').val(), $(this))">📋 Copy Emails</button> ';
			html +=
				'<button type="button" class="button button-primary" onclick="bulkUnsubscribeGoogleEmails()">🚫 Bulk Unsubscribe</button>';
			html += "</div>";
			html += "</div>";
		}

		html += "</div>";
		container.html(html);
	}

	function extractEmailFromGmailMessage(subject) {
		if (subject == "") {
			return null;
		}
		// Remove string `Reporting-MTA` in subject if any
		subject = subject.split("Reporting-MTA")[0];
		var emailRegex = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g;
		var matches = subject.match(emailRegex);

		if (matches && matches.length > 0) {
			return matches[0];
		}

		// If no email in subject, try to get from sender (this would need message details)
		// For now, return null if no email found in subject
		return null;
	}

	function bulkUnsubscribeGoogleEmails() {
		var emails = $("#google-failed-emails-list").val().trim();
		if (!emails) {
			alert("No emails to unsubscribe!");
			return;
		}

		if (
			!confirm(
				"Are you sure you want to unsubscribe " +
					emails.split(",").length +
					" email addresses?"
			)
		) {
			return;
		}

		// Use existing bulk unsubscribe functionality
		$.post(
			mmi_ajax.ajax_url,
			{
				action: "bulk_unsubscribe_email",
				bulk_unsubscribe_nonce: mmi_ajax.nonce,
				unsubscribe_email: emails,
			},
			function (response) {
				if (response.success) {
					var data = response.data;
					alert(
						"✅ Bulk unsubscribe completed!\nProcessed: " +
							data.processed_emails.length +
							" emails\nAffected rows: " +
							data.affected_rows
					);

					if (data.errors.length > 0) {
						console.warn("Unsubscribe errors:", data.errors);
					}
				} else {
					alert("❌ Bulk unsubscribe failed: " + response.data.message);
				}
			}
		).fail(function () {
			alert("❌ Connection error during bulk unsubscribe");
		});
	}

	function updateGoogleTokenCacheInfo(tokenInfo) {
		// var $ = jQuery;
		var status = $("#google-token-status");

		if (tokenInfo.from_cache) {
			status
				.html(
					"🟢 Using cached token (expires in " +
						(tokenInfo.expires_in || "unknown") +
						" seconds)"
				)
				.css("color", "#46b450");
		} else {
			status
				.html(
					"🔄 New token obtained (cached for " +
						(tokenInfo.cache_duration || "unknown") +
						" seconds)"
				)
				.css("color", "#0073aa");
		}
	}

	function escapeHtml(text) {
		var map = {
			"&": "&amp;",
			"<": "&lt;",
			">": "&gt;",
			'"': "&quot;",
			"'": "&#039;",
		};

		return text.replace(/[&<>"']/g, function (m) {
			return map[m];
		});
	}
});
