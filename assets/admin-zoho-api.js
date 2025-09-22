jQuery(document).ready(function ($) {
	// Zoho Search Type Management
	function initZohoSearchType() {
		// Load saved search type from localStorage
		var savedSearchType = localStorage.getItem("zoho_search_type");
		if (savedSearchType) {
			$("#zoho-search-type").val(savedSearchType);
		}

		// Handle search type change
		$("#zoho-search-type").on("change", function () {
			var searchType = $(this).val();
			localStorage.setItem("zoho_search_type", searchType);

			// If "from" is selected, prompt for email
			if (searchType === "from") {
				var savedFromEmail = localStorage.getItem("zoho_from_email") || "";
				var fromEmail = prompt(
					"Nhập email người gửi để tìm kiếm:",
					savedFromEmail
				);

				if (fromEmail && fromEmail.trim() !== "") {
					localStorage.setItem("zoho_from_email", fromEmail.trim());
				} else if (fromEmail === null) {
					// User cancelled, revert to previous selection
					var previousType =
						localStorage.getItem("zoho_search_type_previous") ||
						"subject:Delivery";
					$(this).val(previousType);
					localStorage.setItem("zoho_search_type", previousType);
					return;
				} else {
					// Empty email, show error
					alert("Email người gửi không được để trống!");
					var previousType =
						localStorage.getItem("zoho_search_type_previous") ||
						"subject:Delivery";
					$(this).val(previousType);
					localStorage.setItem("zoho_search_type", previousType);
					return;
				}
			}

			// Save previous selection for fallback
			localStorage.setItem("zoho_search_type_previous", searchType);
		});

		// Save previous selection initially
		localStorage.setItem(
			"zoho_search_type_previous",
			$("#zoho-search-type").val()
		);
	}

	// Initialize Zoho search type when page loads
	initZohoSearchType();

	// Zoho Mail API Integration

	// Refresh Token Generator Tool
	$("#generate-auth-url").on("click", function () {
		var clientId = $("#zoho_client_id").val().trim();
		var clientSecret = $("#zoho_client_secret").val().trim();

		if (!clientId || !clientSecret) {
			if (!clientId) {
				$("#zoho_client_id").focus();
			} else {
				$("#zoho_client_secret").focus();
			}
			my_warning(
				"Vui lòng nhập Client ID và Client Secret vào form chính trước."
			);
			return;
		}

		// Generate authorization URL with default scope
		var scope = "ZohoMail.messages.READ,ZohoMail.accounts.READ"; // Fixed default scope

		// Use WordPress home URL for redirect URI
		var redirectUri =
			mmi_ajax.home_url + "/wp-admin/admin-ajax.php?action=mmi_zoho_callback";
		var responseType = "code";
		var accessType = "offline";

		var authUrl =
			"https://accounts.zoho.com/oauth/v2/auth" +
			"?scope=" +
			encodeURIComponent(scope) +
			"&client_id=" +
			encodeURIComponent(clientId) +
			"&response_type=" +
			responseType +
			"&redirect_uri=" +
			encodeURIComponent(redirectUri) +
			"&access_type=" +
			accessType;

		$("#generated-auth-url").val(authUrl);
		$("#auth-url-section").show();

		// Store for later use
		window.zohoClientId = clientId;
		window.zohoClientSecret = clientSecret;
		window.zohoRedirectUri = redirectUri; // Store redirect URI for token exchange

		// Show notification with selected scope
		$("#token-status")
			.html(
				"✅ Auth URL đã được tạo từ dữ liệu đã lưu!<br><small style='color: #28a745;'>✅ Sử dụng scope: " +
					scope +
					"<br>✅ Sử dụng WordPress callback URL - sẽ tự động xử lý kết quả</small>"
			)
			.css("color", "#28a745");
	});

	// Copy auth URL
	$("#copy-auth-url").on("click", function () {
		var authUrl = $("#generated-auth-url").val();
		navigator.clipboard.writeText(authUrl).then(function () {
			var button = $("#copy-auth-url");
			var originalText = button.text();
			button.text("✓ Copied").css("color", "#46b450");
			setTimeout(function () {
				button.text(originalText).css("color", "");
			}, 2000);
		});
	});

	// Open auth URL
	$("#open-auth-url").on("click", function () {
		var authUrl = $("#generated-auth-url").val();
		window.open(authUrl, "_blank");
	});

	// Enhanced copy functions
	function copyToClipboard(text, button) {
		if (navigator.clipboard) {
			navigator.clipboard.writeText(text).then(function () {
				showCopySuccess(button);
			});
		} else {
			// Fallback for older browsers
			var textArea = document.createElement("textarea");
			textArea.value = text;
			document.body.appendChild(textArea);
			textArea.select();
			document.execCommand("copy");
			document.body.removeChild(textArea);
			showCopySuccess(button);
		}
	}

	function showCopySuccess(button) {
		var originalText = button.text();
		button.text("✓ Copied!").css("color", "#46b450");
		setTimeout(function () {
			button.text(originalText).css("color", "");
		}, 2000);
	}

	// Update copy button handlers
	$("#copy-auth-url")
		.off("click")
		.on("click", function () {
			var authUrl = $("#generated-auth-url").val();
			copyToClipboard(authUrl, $(this));
		});

	// Save Zoho Config - cho phép lưu từng phần
	$("#save-zoho-config").on("click", function (e) {
		e.preventDefault();

		var clientId = $("#zoho_client_id").val().trim();
		var clientSecret = $("#zoho_client_secret").val().trim();

		var button = $(this);
		var status = $("#zoho-status");

		button.prop("disabled", true).text("Saving...");

		// Hiển thị thông tin sẽ được lưu (bỏ refresh_token vì sẽ được lưu tự động từ OAuth callback)
		// Account ID cũng sẽ được lưu tự động từ OAuth callback nếu sử dụng accounts scope
		var fieldsToSave = [];
		if (clientId) fieldsToSave.push("Client ID");
		if (clientSecret) fieldsToSave.push("Client Secret");

		if (fieldsToSave.length < 1) {
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
				action: "mmi_save_zoho_config",
				security: mmi_ajax.nonce,
				client_id: clientId,
				client_secret: clientSecret,
				// Bỏ refresh_token - sẽ được lưu tự động từ OAuth callback
				// Bỏ account_id - sẽ được lưu tự động từ OAuth callback khi sử dụng accounts scope
			},
			success: function (response) {
				if (response.success) {
					status
						.html(
							"✅ " +
								response.data.message +
								"<br><small style='color: #666;'>Refresh Token và Account ID sẽ được lưu tự động từ OAuth callback</small>"
						)
						.css("color", "#46b450");

					// Hiển thị thông tin đã lưu
					console.log("Zoho config saved:", response.data.config);
				} else {
					status
						.text("❌ Failed to save: " + response.data)
						.css("color", "#dc3232");
				}
			},
			error: function (xhr, status, error) {
				$("#zoho-status")
					.text("❌ Save error: " + error)
					.css("color", "#dc3232");
			},
			complete: function () {
				button.prop("disabled", false).text("Save Config");
			},
		});
	});

	// Fetch Failed Delivery Emails - uses account_id from saved config
	$("#fetch-failed-emails").on("click", function () {
		var button = $(this);
		var status = $("#zoho-status");

		// Get search query from select
		var searchType = $("#zoho-search-type").val();
		var searchQuery = searchType;

		// If "from" type, get email from localStorage or prompt
		if (searchType === "from") {
			var fromEmail = localStorage.getItem("zoho_from_email");
			if (!fromEmail) {
				fromEmail = prompt("Nhập email người gửi để tìm kiếm:");
				if (!fromEmail || fromEmail.trim() === "") {
					alert("Email người gửi không được để trống!");
					return;
				}
				localStorage.setItem("zoho_from_email", fromEmail.trim());
			}
			searchQuery = "from:" + fromEmail.trim();
		}

		button.prop("disabled", true).text("Fetching...");
		status
			.text("Đang tìm kiếm email với query: " + searchQuery + "...")
			.css("color", "#666");

		$.post(
			mmi_ajax.ajax_url,
			{
				action: "mmi_zoho_fetch_failed_emails",
				security: mmi_ajax.nonce,
				search_query: searchQuery,
				// No need to send account_id - server will use saved config
			},
			function (response) {
				if (response.success && response.data) {
					//
					var cacheEmailShift = localStorage.getItem("last_unsubscribed_email");

					// Display failed emails and get count
					let lens = displayFailedEmails(
						response.data.messages || response.data,
						cacheEmailShift
					);

					//
					if (cacheEmailShift !== null) {
						$(
							"." + cacheEmailShift.replace(/[^a-zA-Z0-9]/g, "_") + ""
						).addClass("orgcolor");
					}

					//
					var tokenInfo = response.data.token_info;
					if (tokenInfo) {
						updateTokenCacheInfo(tokenInfo);
					}
					status
						.text(
							"✅ Tìm thấy " +
								lens.len +
								" email với query: " +
								searchQuery +
								". Đã chọn " +
								lens.checked +
								" email để hủy đăng ký."
						)
						.css("color", "#46b450");
				} else {
					var errorMsg =
						response.data ||
						"Không tìm thấy email nào với query: " + searchQuery;
					status.text("❌ " + errorMsg).css("color", "#dc3232");
				}
			}
		)
			.fail(function () {
				status.text("❌ Lỗi kết nối server.").css("color", "#dc3232");
			})
			.always(function () {
				button.prop("disabled", false).text("Fetch Failed Delivery Emails");
			});
	});

	// Display failed emails
	function displayFailedEmails(messages, cacheEmailShift) {
		var container = $("#failed-emails-container");
		var list = $("#failed-emails-list");
		var count = $("#failed-emails-count");

		if (messages.length < 1) {
			container.hide();
			return 0;
		}

		list.empty();
		var failedEmails = [];
		var checkboxChecked = " checked";
		var checkedLen = 0;

		messages.forEach(function (message, index) {
			// Extract failed email from message content or subject
			var failedEmail = extractFailedEmailFromMessage(message);
			if (
				failedEmail &&
				failedEmails.indexOf(failedEmail) === -1 &&
				typeof message.subject == "string" &&
				message.subject.includes("Delivery ")
			) {
				failedEmails.push(failedEmail);

				// từ đoạn email đã unsubscribe gần nhất thì thôi không check nữa
				if (failedEmail === cacheEmailShift) {
					checkboxChecked = "";
				}
				if (checkboxChecked != "") {
					checkedLen++;
				}

				var item = $(
					'<div class="failed-email-item" style="padding: 5px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 8px;"></div>'
				);
				var checkbox = $(
					'<input type="checkbox" class="failed-email-checkbox" value="' +
						failedEmail +
						'"' +
						checkboxChecked +
						">"
				);
				var emailSpan = $(
					'<a href="https://' +
						window.location.hostname +
						"/wp-admin/tools.php?page=email-campaigns&filter=1&search_email=" +
						encodeURIComponent(failedEmail) +
						'" target="_blank" class="' +
						failedEmail.replace(/[^a-zA-Z0-9]/g, "_") +
						'" style="font-family: monospace; font-size: 12px;">' +
						failedEmail +
						"</a>"
				);
				var subject = $(
					'<small class="' +
						(typeof message.status != "undefined" && message.status == "0"
							? "bold"
							: "") +
						'" style="color: #666; margin-left: auto;">' +
						(message.subject || "") +
						": " +
						(message.summary || "").substring(0, 155) +
						"...</small>"
				);

				item.append(checkbox, emailSpan, subject);
				list.append(item);
			}
		});

		count.text("(" + failedEmails.length + " emails)");
		container.show();
		return {
			len: failedEmails.length,
			checked: checkedLen,
		};
	}

	// Extract failed email from message
	function extractFailedEmailFromMessage(message) {
		// Try to extract from subject first
		var subject = message.summary || "";
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

	// Select all failed emails
	$("#select-all-failed").on("click", function () {
		var checkboxes = $(".failed-email-checkbox");
		var allChecked =
			checkboxes.length > 0 &&
			checkboxes.filter(":checked").length === checkboxes.length;

		checkboxes.prop("checked", !allChecked);
		$(this).text(allChecked ? "Select All" : "Deselect All");
	});

	// Bulk unsubscribe failed emails
	$("#bulk-unsubscribe-failed").on("click", function () {
		//
		let is_processing = $(this).attr("data-processing") || "";
		if (is_processing != "") {
			if (confirm("Bạn có chắc chắn muốn hủy bỏ quá trình không?")) {
				$("#bulk-unsubscribe-failed")
					.removeAttr("data-processing")
					.text("Bulk Unsubscribe Selected");
			}
			return;
		}

		// Collect selected emails
		var selectedEmails = [];
		$(".failed-email-checkbox:checked").each(function () {
			selectedEmails.push($(this).val());
		});

		if (selectedEmails.length < 1) {
			my_error("Vui lòng chọn ít nhất 1 email để unsubscribe.");
			return;
		}

		if (
			!confirm(
				"Bạn có chắc chắn muốn unsubscribe " +
					selectedEmails.length +
					" email(s) này?"
			)
		) {
			return;
		}
		console.log("Unsubscribing emails:", selectedEmails);

		// Set processing state
		$(this).attr("data-processing", "1").text("Processing...");

		// chuyển về div #failed-emails-list
		$("html, body").animate(
			{
				scrollTop: $("#failed-emails-list").offset().top - 90,
			},
			200
		);
		// return;

		// Process each email
		var processed = 0;
		var errors = [];
		var prevEmailShift = null;

		function afterUnsubscribe(email, cl) {
			processed++;

			$("." + email.replace(/[^a-zA-Z0-9]/g, "_") + "").addClass(
				typeof cl == "undefined" ? "greencolor" : cl
			);

			$('input.failed-email-checkbox[value="' + email + '"]').prop(
				"checked",
				false
			);

			selectedSendEmails();
		}

		function selectedSendEmails() {
			// Kiểm tra nếu đã xử lý xong tất cả email
			if (selectedEmails.length < 1) {
				$("#bulk-unsubscribe-failed")
					.removeAttr("data-processing")
					.text("Bulk Unsubscribe Selected");
				my_notice("✅ Hoàn thành! Đã unsubscribe " + processed + " emails.");
				return;
			}
			// Kiểm tra trạng thái xử lý
			let is_processing =
				$("#bulk-unsubscribe-failed").attr("data-processing") || "";
			if (is_processing == "") {
				my_warning(
					"Quá trình đã bị hủy bỏ. Đã unsubscribe " + processed + " emails."
				);
				return;
			}
			// lấy email đầu tiên trong mảng và gửi ajax
			var email = selectedEmails.shift();

			// Gửi ajax để unsubscribe email
			$.ajax({
				url: ajaxurl,
				method: "POST",
				data: {
					action: "bulk_unsubscribe_email",
					unsubscribe_email: email,
					bulk_unsubscribe_nonce: $(
						'input[name="bulk_unsubscribe_nonce"]'
					).val(),
				},
				success: function (response) {
					console.log("Unsubscribe response for", email, ":", response);
					afterUnsubscribe(
						email,
						typeof response.affected_rows != "undefined" &&
							response.affected_rows > 0
							? "greencolor"
							: "orgcolor"
					);

					// lưu vào localstorage email đầu tiên xử lý thành công
					if (prevEmailShift === null) {
						localStorage.setItem("last_unsubscribed_email", email);
						prevEmailShift = email;
						console.log("Cached last unsubscribed email:", email);
					}
				},
				error: function (xhr, status, error) {
					errors.push(email + ": " + error);
					afterUnsubscribe(email, "redcolor");
				},
			});
		}
		setTimeout(() => {
			selectedSendEmails();
		}, 200);
	});

	// Zoho Token Cache Management
	function updateTokenCacheInfo(tokenInfo) {
		var cacheInfoDiv = $("#zoho-cache-info");
		if (cacheInfoDiv.length < 1) {
			// Create cache info div if it doesn't exist
			$("#zoho-status").after(
				'<div id="zoho-cache-info" style="margin-top: 10px; padding: 10px; background: #f0f0f0; border-radius: 4px;"></div>'
			);
			cacheInfoDiv = $("#zoho-cache-info");
		}

		var html = "<strong>🔑 Access Token Info:</strong><br>";
		html +=
			"Source: " + (tokenInfo.from_cache ? "📦 Cache" : "🌐 API") + "<br>";

		if (tokenInfo.expires_in) {
			html +=
				"Token expires in: " +
				Math.floor(tokenInfo.expires_in / 60) +
				" minutes<br>";
		}

		if (tokenInfo.cache_duration) {
			html +=
				"Cache duration: " +
				Math.floor(tokenInfo.cache_duration / 60) +
				" minutes";
		}

		cacheInfoDiv.html(html);
	}

	// Clear token cache
	$("#clear-token-cache").on("click", function () {
		// Confirm before clearing cache
		if (!confirm("Bạn có chắc chắn muốn xóa token cache?")) {
			return;
		}

		// Clear token cache via AJAX
		var button = $(this);
		var status = $("#zoho-status");

		button.prop("disabled", true).text("Clearing...");
		status.text("Đang xóa token cache...").css("color", "#666");

		$.post(
			mmi_ajax.ajax_url,
			{
				action: "mmi_clear_zoho_token_cache",
				security: mmi_ajax.nonce,
			},
			function (response) {
				if (response.success) {
					status.text("✅ " + response.data.message).css("color", "#46b450");
					$("#zoho-cache-info").html(
						"<strong>🔑 Access Token Cache:</strong> Cleared"
					);
				} else {
					var errorMsg = response.data || "Không thể xóa cache.";
					status.text("❌ " + errorMsg).css("color", "#dc3232");
				}
			}
		)
			.fail(function () {
				status.text("❌ Lỗi kết nối server.").css("color", "#dc3232");
			})
			.always(function () {
				button.prop("disabled", false).text("Clear Token Cache");
			});
	});

	// Show secret token
	$("#show-secret-token").on("click", function () {
		var is_hidden = $("#zoho_client_secret").hasClass("is-token-hidden");
		if (is_hidden) {
			$("#zoho_client_secret, #zoho_refresh_token").removeClass(
				"is-token-hidden"
			);
		} else {
			$("#zoho_client_secret, #zoho_refresh_token").addClass("is-token-hidden");
		}

		// Show/hide password
		$(this).text(is_hidden ? "Hide Secret Token" : "Show Secret Token");
	});

	// Get token cache info
	$("#check-token-cache").on("click", function () {
		var button = $(this);
		var status = $("#zoho-status");

		button.prop("disabled", true).text("Checking...");
		status.text("Đang kiểm tra token cache...").css("color", "#666");

		$.post(
			mmi_ajax.ajax_url,
			{
				action: "mmi_get_zoho_token_cache_info",
				security: mmi_ajax.nonce,
			},
			function (response) {
				if (response.success) {
					var data = response.data;
					var html = "<strong>🔑 Token Cache Status:</strong><br>";
					html +=
						"Cache exists: " +
						(data.cache_exists ? "✅ Yes" : "❌ No") +
						"<br>";
					html += "Cache timeout: " + data.cache_timeout + "<br>";
					html +=
						"Token preview: <code>" +
						(data.token_preview == ""
							? "No cached token"
							: data.token_preview.substring(0, 20) + "...") +
						"</code>";

					$("#zoho-cache-info").html(html);
					status.text("✅ Cache info updated").css("color", "#46b450");
				} else {
					var errorMsg = response.data || "Không thể lấy thông tin cache.";
					status.text("❌ " + errorMsg).css("color", "#dc3232");
				}
			}
		)
			.fail(function () {
				status.text("❌ Lỗi kết nối server.").css("color", "#dc3232");
			})
			.always(function () {
				button.prop("disabled", false).text("Check Token Cache");
			});
	});

	// Clear Zoho Account ID
	$("#clear-account-id").on("click", function () {
		// Confirm before clearing
		if (
			!confirm(
				"Bạn có chắc chắn muốn xóa Account ID? Điều này sẽ cho phép bạn kết nối với một tài khoản Zoho khác."
			)
		) {
			return;
		}

		var button = $(this);
		var status = $("#zoho-status");

		button.prop("disabled", true).text("Clearing...");
		status.text("Đang xóa Account ID...").css("color", "#666");

		$.post(
			mmi_ajax.ajax_url,
			{
				action: "mmi_clear_zoho_account_id",
				security: mmi_ajax.nonce,
			},
			function (response) {
				if (response.success) {
					status.text("✅ " + response.data.message).css("color", "#46b450");

					// Clear the account ID input field
					$("#zoho_account_id").val("");

					// Show success message with next steps
					setTimeout(function () {
						status
							.html(
								"✅ Account ID đã được xóa!<br><small style='color: #666;'>Bây giờ bạn có thể tạo Auth URL mới để kết nối với tài khoản Zoho khác.</small>"
							)
							.css("color", "#46b450");
					}, 1000);
				} else {
					var errorMsg = response.data || "Không thể xóa Account ID.";
					status.text("❌ " + errorMsg).css("color", "#dc3232");
				}
			}
		)
			.fail(function () {
				status.text("❌ Lỗi kết nối server.").css("color", "#dc3232");
			})
			.always(function () {
				button.prop("disabled", false).text("Clear Account ID");
			});
	});
});
