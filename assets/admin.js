jQuery(document).ready(function ($) {
	function my_warning(message) {
		return my_notice(message, "warning");
	}

	function my_error(message) {
		return my_notice(message, "error");
	}

	function my_notice(message, type) {
		if ($("#parent-notice").length < 1) {
			$("body").append('<div id="parent-notice"></div>');
		}

		//
		var notice = $('<div class="my-notice"></div>');
		notice.addClass(
			"my-notice-" + (typeof type == "undefined" ? "success" : type)
		);
		notice.text(message);
		$("#parent-notice").append(notice);
		setTimeout(function () {
			notice.fadeOut(300, function () {
				$(this).remove();
			});
		}, 9000);
	}

	// Handle campaign selection radio buttons
	$('input[name="campaign_option"]').on("change", function () {
		if ($(this).val() === "existing") {
			$("#existing-campaign-group").show();
			$("#new-campaign-group").hide();
			$("#existing_campaign").prop("required", true);
			$("#new_campaign_name").prop("required", false);
		} else {
			$("#existing-campaign-group").hide();
			$("#new-campaign-group").show();
			$("#existing_campaign").prop("required", false);
			$("#new_campaign_name").prop("required", true);
		}
	});

	// Initialize campaign fields on page load
	var selectedOption = $('input[name="campaign_option"]:checked').val();
	if (selectedOption === "existing") {
		$("#existing-campaign-group").show();
		$("#new-campaign-group").hide();
	} else {
		$("#existing-campaign-group").hide();
		$("#new-campaign-group").show();
	}

	// Form validation before submit
	$("#mmi-upload-form").on("submit", function (e) {
		var campaignOption = $('input[name="campaign_option"]:checked').val();

		if (campaignOption === "existing") {
			var selectedCampaign = $("#existing_campaign").val();
			if (!selectedCampaign) {
				e.preventDefault();
				my_warning("Please select an existing campaign.");
				$("#existing_campaign").focus();
				return false;
			}
		} else if (campaignOption === "new") {
			var campaignName = $("#new_campaign_name").val().trim();
			if (!campaignName) {
				e.preventDefault();
				my_warning("Please enter a campaign name.");
				$("#new_campaign_name").focus();
				return false;
			}
			if (campaignName.length > 255) {
				e.preventDefault();
				my_warning("Campaign name is too long. Maximum 255 characters.");
				$("#new_campaign_name").focus();
				return false;
			}
		}

		// Check if file is selected
		var fileInput = $("#import_file")[0];
		if (!fileInput.files.length) {
			e.preventDefault();
			my_warning("Please select a file to import.");
			$("#import_file").focus();
			return false;
		}

		// Check if email column is mapped
		var emailColumn = $("#email_column").val();
		if (!emailColumn) {
			e.preventDefault();
			my_warning("Please map the email column. This field is required.");
			$("#email_column").focus();
			return false;
		}
	});

	// Handle file selection and dynamic column loading
	$("#import_file").on("change", function () {
		var file = this.files[0];

		if (!file) {
			$("#file-preview").hide();
			$("#column-mapping-section").hide();
			return;
		}

		// Show loading
		$("#file-preview").show();
		$(".loading-spinner").show();
		$("#file-info").empty();
		$("#column-mapping-section").hide();

		// Create FormData for AJAX
		var formData = new FormData();
		formData.append("action", "read_file_headers");
		formData.append("file", file);
		formData.append("nonce", mmi_ajax.nonce);

		// Send AJAX request to read file headers
		$.ajax({
			url: mmi_ajax.ajax_url,
			type: "POST",
			data: formData,
			processData: false,
			contentType: false,
			success: function (response) {
				$(".loading-spinner").hide();

				if (response.success) {
					var data = response.data;

					// Show file information
					var fileInfo =
						'<div class="file-info-box" style="background: #f0f0f0; padding: 10px; border-radius: 4px; margin-bottom: 15px;">';
					fileInfo += "<strong>File Information:</strong><br>";
					fileInfo += "Name: " + data.file_info.name + "<br>";
					fileInfo += "Size: " + data.file_info.size + "<br>";
					fileInfo += "Type: " + data.file_info.type.toUpperCase() + "<br>";
					fileInfo += "Columns found: " + data.headers.length;
					fileInfo += "</div>";

					// Show column preview
					fileInfo +=
						'<div class="columns-preview" style="background: #fff; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">';
					fileInfo += "<strong>Available Columns:</strong><br>";
					fileInfo +=
						'<div style="display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px;">';

					data.headers.forEach(function (header, index) {
						fileInfo +=
							'<span style="background: #e1ecf4; padding: 4px 8px; border-radius: 3px; font-size: 12px;">';
						fileInfo += index + 1 + ". " + header;
						fileInfo += "</span>";
					});

					fileInfo += "</div></div>";

					$("#file-info").html(fileInfo);

					// Populate column dropdown options
					populateColumnDropdowns(data.headers, data.suggested_mapping);

					// Show column mapping section
					$("#column-mapping-section").show();
				} else {
					$("#file-info").html(
						'<div class="notice notice-error"><p>Error: ' +
							response.data +
							"</p></div>"
					);
				}
			},
			error: function (xhr, status, error) {
				$(".loading-spinner").hide();
				$("#file-info").html(
					'<div class="notice notice-error"><p>Error reading file. Please try again.</p></div>'
				);
				console.error("AJAX Error:", error);
			},
		});
	});

	// Populate dropdown selects with column options
	function populateColumnDropdowns(headers, suggestedMapping) {
		var selects = [
			"email_column",
			"first_name_column",
			"last_name_column",
			"name_column",
			"phone_column",
			"address_column",
			"city_column",
			"state_column",
			"zip_code_column",
		];

		selects.forEach(function (selectId) {
			var $select = $("#" + selectId);

			// Clear existing options except the first (placeholder)
			$select.find("option:not(:first)").remove();

			// Add new options from file headers
			headers.forEach(function (header, index) {
				var option = new Option(header, index);
				$select.append(option);
			});

			// Set auto-suggested mapping if available
			if (suggestedMapping[selectId] !== undefined) {
				$select.val(suggestedMapping[selectId]);
				$select.addClass("suggested-mapping");

				// Add visual indicator for auto-detected mappings
				if (!$select.siblings(".suggested-indicator").length) {
					$select.after(
						'<span class="suggested-indicator" style="color: #00a0d2; font-size: 12px; margin-left: 8px;">✓ Auto-detected</span>'
					);
				}
			}
		});

		// Remove suggestion indicator when user manually changes selection
		$('select[name*="_column"]')
			.off("change.suggestion")
			.on("change.suggestion", function () {
				$(this).removeClass("suggested-mapping");
				$(this).siblings(".suggested-indicator").remove();
			});
	}

	// Handle form submission
	$("#mmi-upload-form").on("submit", function (e) {
		var fileInput = $("#import_file");
		var file = fileInput[0].files[0];

		if (!file) {
			my_warning("Please select a file to import.");
			e.preventDefault();
			return false;
		}

		// Validate campaign selection
		var campaignOption = $('input[name="campaign_option"]:checked').val();
		if (campaignOption === "existing") {
			var existingCampaign = $("#existing_campaign").val();
			if (!existingCampaign) {
				my_warning("Please select an existing campaign.");
				e.preventDefault();
				$("#existing_campaign").focus();
				return false;
			}
		} else {
			var newCampaignName = $("#new_campaign_name").val().trim();
			if (!newCampaignName) {
				my_warning("Please enter a name for the new campaign.");
				e.preventDefault();
				$("#new_campaign_name").focus();
				return false;
			}
		}

		// Check if email column is selected (required)
		var emailColumn = $("#email_column").val();
		if (!emailColumn) {
			e.preventDefault();
			my_warning("Please select an Email column. This field is required.");
			$("#email_column").focus();
			return false;
		}

		// Validate file size (max 10MB)
		if (file.size > 10 * 1024 * 1024) {
			my_warning("File size must be less than 10MB.");
			e.preventDefault();
			return false;
		}

		// Validate file extension
		var allowedExtensions = ["xlsx", "xls", "csv"];
		var fileExtension = file.name.split(".").pop().toLowerCase();

		if (allowedExtensions.indexOf(fileExtension) === -1) {
			my_warning("Please select a valid file format (.xlsx, .xls, .csv).");
			e.preventDefault();
			return false;
		}

		// Show progress section
		$(".mmi-progress-section").show();
		$(".progress-text").text("Uploading file...");
		$(".progress-fill").css("width", "10%");

		// Disable submit button during processing
		$(this)
			.find('input[type="submit"]')
			.prop("disabled", true)
			.val("Importing...");

		return true;
	});

	// Drag and drop functionality
	var dropZone = $(".mmi-upload-section");

	dropZone.on("dragover", function (e) {
		e.preventDefault();
		e.stopPropagation();
		$(this).addClass("drag-over");
	});

	dropZone.on("dragleave", function (e) {
		e.preventDefault();
		e.stopPropagation();
		$(this).removeClass("drag-over");
	});

	dropZone.on("drop", function (e) {
		e.preventDefault();
		e.stopPropagation();
		$(this).removeClass("drag-over");

		var files = e.originalEvent.dataTransfer.files;
		if (files.length > 0) {
			$("#import_file")[0].files = files;
			$("#import_file").trigger("change");
		}
	});

	// Add drag and drop styling
	$("<style>")
		.prop("type", "text/css")
		.html(
			".drag-over { background-color: #e8f4f8; border: 2px dashed #0073aa; }" +
				".suggested-mapping { border-color: #00a0d2; background-color: #f0f8ff; }" +
				".suggested-indicator { color: #00a0d2; font-weight: 500; }"
		)
		.appendTo("head");

	// Reset form function
	window.resetImportForm = function () {
		$("#import_file").val("");
		$("#file-preview").hide();
		$("#column-mapping-section").hide();
		$("#mmi-upload-form")
			.find('input[type="submit"]')
			.prop("disabled", false)
			.val("Import Data");
		$(".suggested-indicator").remove();
		$('select[name*="_column"]').removeClass("suggested-mapping");
	};

	// Email Placeholder Copy Functionality
	$(document).on("click", ".placeholder-item", function (e) {
		e.preventDefault();

		var placeholder = $(this).data("placeholder");
		var $item = $(this);

		// Copy to clipboard
		navigator.clipboard
			.writeText(placeholder)
			.then(function () {
				// Visual feedback
				$item.addClass("copied");

				// Show notification
				showCopyNotification("Copied: " + placeholder);

				// Remove copied class after animation
				setTimeout(function () {
					$item.removeClass("copied");
				}, 600);
			})
			.catch(function (err) {
				// Fallback for older browsers
				var textArea = document.createElement("textarea");
				textArea.value = placeholder;
				document.body.appendChild(textArea);
				textArea.select();
				document.execCommand("copy");
				document.body.removeChild(textArea);

				// Visual feedback
				$item.addClass("copied");
				showCopyNotification("Copied: " + placeholder);

				setTimeout(function () {
					$item.removeClass("copied");
				}, 600);
			});
	});

	// Show copy notification
	function showCopyNotification(message) {
		// Remove existing notification
		$(".copy-notification").remove();

		// Create notification
		var $notification = $(
			'<div class="copy-notification">' + message + "</div>"
		);
		$("body").append($notification);

		// Show notification
		setTimeout(function () {
			$notification.addClass("show");
		}, 10);

		// Hide notification after 2 seconds
		setTimeout(function () {
			$notification.addClass("hide");
			setTimeout(function () {
				$notification.remove();
			}, 300);
		}, 2000);
	}

	// Campaign form validation and submission
	$('form[method="post"]').on("submit", function (e) {
		// Check if this is a campaign form
		if ($(this).find('input[name="campaign_name"]').length) {
			var campaignName = $('input[name="campaign_name"]').val().trim();
			var emailSubject = $('input[name="email_subject"]').val().trim();

			if (!campaignName) {
				my_warning("Please enter a campaign name.");
				$('input[name="campaign_name"]').focus();
				e.preventDefault();
				return false;
			}

			if (!emailSubject) {
				my_warning("Please enter an email subject.");
				$('input[name="email_subject"]').focus();
				e.preventDefault();
				return false;
			}

			// Force sync content from TinyMCE to textarea before submit
			if (typeof tinyMCE !== "undefined") {
				tinyMCE.triggerSave();
				// Also manually sync the specific editor
				var editor = tinyMCE.get("email_content");
				if (editor && !editor.isHidden()) {
					editor.save();
				}
			}

			// Get content from WordPress editor (no longer required - template fallback)
			var emailContent = "";
			if (typeof tinyMCE !== "undefined" && tinyMCE.get("email_content")) {
				emailContent = tinyMCE.get("email_content").getContent();
			} else {
				emailContent = $("#email_content").val();
			}

			// Note: Email content is now optional - if empty, template content will be used
			console.log(
				"Campaign form submission - Email content length:",
				emailContent.length,
				"(Empty content will use selected template)"
			);
			console.log("Form action:", $(this).find('input[name="action"]').val());

			// Show loading state
			var $submitBtn = $(this).find('input[type="submit"]');
			$submitBtn.prop("disabled", true);
			var originalText = $submitBtn.val();
			$submitBtn.val("Saving...");

			// Re-enable after 5 seconds as failsafe
			setTimeout(function () {
				$submitBtn.prop("disabled", false).val(originalText);
			}, 5000);
		}
	});

	// nếu page = email-campaigns và có tham số email_page thì tự động cuộn chuột đến #imported-email-list
	function getUrlParameter(name) {
		var regex = new RegExp("[?&]" + name + "=([^&#]*)");
		var results = regex.exec(window.location.href);
		return results ? decodeURIComponent(results[1]) : null;
	}

	function addCurrentMenu(node, cl) {
		$(node).addClass("current").addClass(cl);
		$("a." + cl)
			.closest("li")
			.addClass("current");
	}

	if (pagenow == "tools_page_email-campaigns") {
		if (getUrlParameter("email_page") || getUrlParameter("filter")) {
			// Thực hiện hành động nào đó
			$("html, body").animate(
				{
					scrollTop: $("#imported-email-list").offset().top - 60,
				},
				1000
			);
		}

		if (getUrlParameter("search_email")) {
			$("#search_email").focus();
		}

		if (getUrlParameter("bulk_unsubscribed")) {
			$("#unsubscribe_email").focus();
		}

		addCurrentMenu(
			'#menu-tools a[href="tools.php?page=email-campaigns"]',
			"email-campaigns"
		);
	}

	// Handle clickable status toggle
	$(document).on("click", ".clickable-status", function (e) {
		e.preventDefault();

		var $this = $(this);
		var emailId = $this.data("email-id");
		var field = $this.data("field");
		var currentValue = $this.data("current-value");

		// Show loading state
		var originalText = $this.text();
		$this.text("Updating...");
		$this.addClass("updating");

		// Make AJAX request
		$.ajax({
			url: mmi_ajax.ajax_url,
			type: "POST",
			data: {
				action: "toggle_email_status",
				nonce: mmi_ajax.nonce,
				email_id: emailId,
				field: field,
				current_value: currentValue,
			},
			success: function (response) {
				if (response.success) {
					// Update the display
					$this.text(response.data.display_text);
					$this.data("current-value", response.data.new_value);

					// Update CSS class
					$this.removeClass("email-" + field + "-" + currentValue);
					$this.addClass("email-" + field + "-" + response.data.new_value);
				} else {
					my_error("Error: " + response.data);
					$this.text(originalText);
				}
			},
			error: function () {
				my_error("Failed to update status. Please try again.");
				$this.text(originalText);
			},
			complete: function () {
				$this.removeClass("updating");
			},
		});
	});

	// URL Detection for Email Content
	function detectURLsInContent() {
		let content = "";

		// Get content from TinyMCE editor if available, otherwise from textarea
		if (typeof tinymce !== "undefined" && tinymce.get("email_content")) {
			content = tinymce.get("email_content").getContent();
		} else {
			content = $("#email_content").val() || "";
		}
		// console.log(content);

		// Find all URLs in the content
		const urls = [];
		const emails = [];
		const placeholders = [];

		// tìm tất cả thẻ a và lấy href trong content
		const anchorRegex = /<a [^>]*href="([^"]*)"[^>]*>/gi;
		let anchorMatch;
		while ((anchorMatch = anchorRegex.exec(content)) !== null) {
			const href = $.trim(anchorMatch[1]);
			if (!href || href == "#" || href.startsWith("javascript:")) {
				console.log("Skipping invalid href:", href);
				continue;
			}

			// nếu đây là email
			if (href.startsWith("mailto:")) {
				emails.push(href);
			} else if (href.startsWith("{") && href.endsWith("}")) {
				// nếu đây là placeholder
				placeholders.push(href);
			} else {
				urls.push(href);
			}
		}
		// console.log("Anchor URLs:", urls);
		// console.log("Anchor Emails:", emails);
		// console.log("Anchor Placeholders:", placeholders);

		// Update the display
		updateURLDisplay(urls, emails, placeholders);
	}

	function updateURLDisplay(urls, emails, placeholders) {
		const container = $("#detected-urls-list");
		let html = "";

		if (urls.length === 0 && emails.length === 0 && placeholders.length === 0) {
			html = '<span class="detected-urls-empty">No URLs detected...</span>';
		} else {
			if (urls.length > 0) {
				html +=
					'<div class="detected-urls-title"><strong>🌐 Web URLs (' +
					urls.length +
					"):</strong></div>";
				urls.forEach(function (url, index) {
					const displayUrl =
						url.length > 180 ? url.substring(0, 180) + "..." : url;
					html += '<div class="detected-urls-node">';
					html +=
						'<a target="_blank" href="' + url + '">' + displayUrl + "</a>";
					html += "</div>";
				});
			}

			if (emails.length > 0) {
				html +=
					'<div class="detected-urls-title"><strong>📧 Email Links (' +
					emails.length +
					"):</strong></div>";
				emails.forEach(function (email, index) {
					html += '<div class="detected-urls-node">';
					html += "<span>" + email + "</span>";
					html += "</div>";
				});
			}

			if (placeholders.length > 0) {
				html +=
					'<div class="detected-urls-title"><strong>🔗 URL Placeholders (' +
					placeholders.length +
					"):</strong></div>";
				placeholders.forEach(function (placeholder, index) {
					html += '<div class="detected-urls-node">';
					html += '<span style="color: #50575e;">' + placeholder + "</span>";
					html += "</div>";
				});
			}
		}

		container.html(html);
	}

	// Initialize URL detection on page load
	if ($("#email_content").length > 0) {
		// Detect URLs immediately
		setTimeout(detectURLsInContent, 1000);

		// Monitor TinyMCE editor changes
		$(document).on("tinymce-editor-init", function (event, editor) {
			if (editor.id === "email_content") {
				editor.on("keyup", function () {
					setTimeout(detectURLsInContent, 3000);
				});

				editor.on("change", function () {
					setTimeout(detectURLsInContent, 2000);
				});

				editor.on("paste", function () {
					setTimeout(detectURLsInContent, 1000);
				});
			}
		});

		// Monitor textarea changes (fallback)
		$("#email_content").on("keyup paste change", function () {
			setTimeout(detectURLsInContent, 1000);
		});

		// Monitor when switching between Visual/Text tabs
		$(document).on("click", ".wp-switch-editor", function () {
			setTimeout(detectURLsInContent, 1000);
		});
	}

	// khi người dùng paste 1 đoạn văn bản vào #unsubscribe_email thì tự động xóa khoảng trắng thừa và lọc lấy địa chỉ email hợp lệ trong chuỗi đó
	$("#unsubscribe_email").on("change", function (e) {
		let a = $(this).val();
		a = a.replace(/\s+/g, " ").trim();
		// console.log(a.split(/[\s,;]+/));
		a = a.split(/[\s,;]+/).filter(function (email) {
			// Lọc các email hợp lệ
			var emailRegex = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
			return emailRegex.test(email);
		});
		$(this).val(a.join(", "));
		$("#unsubscribe_submit").prop("disabled", a.length === 0);
	});

	// Handle Reset to Template button
	$("#reset-to-template-btn").on("click", function (e) {
		e.preventDefault();

		if (
			!confirm(
				"This will clear all current email content and load the default template content."
			)
		) {
			return;
		}

		// Clear both visual and text editor content
		if (typeof tinymce !== "undefined") {
			var editor = tinymce.get("email_content");
			if (editor) {
				editor.setContent("");
			}
		}

		// Also clear textarea (for text mode)
		$("#email_content").val("");
	});

	// Handle Add UTM Parameters button
	$("#add-utm-params-btn").on("click", function (e) {
		e.preventDefault();

		var emailUrlInput = $("#email_url");
		var currentUrl = emailUrlInput.val().trim();

		if (!currentUrl) {
			my_warning("Vui lòng nhập URL trước khi thêm UTM parameters.");
			emailUrlInput.focus();
			return;
		}

		// Parse current URL
		try {
			var url = new URL(currentUrl);
			var params = new URLSearchParams(url.search);

			// Get campaign name for UTM parameters
			var campaignName =
				$('input[name="campaign_name"]').val().trim() || "email-campaign";
			var siteName = window.location.hostname || "website";

			// Add UTM parameters if they don't exist
			if (!params.has("utm_source")) {
				params.set("utm_source", "email");
			}
			if (!params.has("utm_medium")) {
				params.set("utm_medium", "email_marketing");
			}
			if (!params.has("utm_campaign")) {
				params.set(
					"utm_campaign",
					campaignName.toLowerCase().replace(/\s+/g, "_")
				);
			}
			if (!params.has("utm_content")) {
				params.set("utm_content", "email_link");
			}

			// Rebuild URL with UTM parameters
			url.search = params.toString();
			var newUrl = url.toString();

			// Update input field
			emailUrlInput.val(newUrl);
		} catch (error) {
			my_error("URL không hợp lệ. Vui lòng kiểm tra lại format URL.");
			emailUrlInput.focus();
		}
	});

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

		// Generate authorization URL
		var scope = $("#zoho_scope").val() || "ZohoMail.messages.READ"; // Get selected scope

		// Save selected scope for callback use
		$.post(mmi_ajax.ajax_url, {
			action: "mmi_save_zoho_scope",
			security: mmi_ajax.nonce,
			scope: scope,
		});

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

		button.prop("disabled", true).text("Fetching...");
		status.text("Đang tìm kiếm email failed delivery...").css("color", "#666");

		$.post(
			mmi_ajax.ajax_url,
			{
				action: "mmi_zoho_fetch_failed_emails",
				security: mmi_ajax.nonce,
				// No need to send account_id - server will use saved config
			},
			function (response) {
				if (response.success && response.data) {
					//
					var cacheEmailShift = localStorage.getItem("last_unsubscribed_email");

					// Display failed emails and get count
					let len = displayFailedEmails(
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
						.text("✅ Tìm thấy " + len + " email failed delivery.")
						.css("color", "#46b450");
				} else {
					var errorMsg =
						response.data || "Không tìm thấy email failed delivery nào.";
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

		if (messages.length === 0) {
			container.hide();
			return;
		}

		list.empty();
		var failedEmails = [];
		var checkboxChecked = " checked";

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
		return failedEmails.length;
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

		if (selectedEmails.length === 0) {
			my_warning("Vui lòng chọn ít nhất 1 email để unsubscribe.");
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
			if (selectedEmails.length === 0) {
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
		if (cacheInfoDiv.length === 0) {
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
});
