jQuery(document).ready(function ($) {
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
				alert("Please select an existing campaign.");
				$("#existing_campaign").focus();
				return false;
			}
		} else if (campaignOption === "new") {
			var campaignName = $("#new_campaign_name").val().trim();
			if (!campaignName) {
				e.preventDefault();
				alert("Please enter a campaign name.");
				$("#new_campaign_name").focus();
				return false;
			}
			if (campaignName.length > 255) {
				e.preventDefault();
				alert("Campaign name is too long. Maximum 255 characters.");
				$("#new_campaign_name").focus();
				return false;
			}
		}

		// Check if file is selected
		var fileInput = $("#import_file")[0];
		if (!fileInput.files.length) {
			e.preventDefault();
			alert("Please select a file to import.");
			$("#import_file").focus();
			return false;
		}

		// Check if email column is mapped
		var emailColumn = $("#email_column").val();
		if (!emailColumn) {
			e.preventDefault();
			alert("Please map the email column. This field is required.");
			$("#email_column").focus();
			return false;
		}
	});

	// Character counter for campaign description
	$("#new_campaign_description").on("input", function () {
		var length = $(this).val().length;
		var maxLength = 500;
		var remaining = maxLength - length;

		if (!$(this).next(".char-counter").length) {
			$(this).after('<small class="char-counter description"></small>');
		}

		var counterText = remaining + " characters remaining";
		if (remaining < 0) {
			counterText = "Exceeded by " + Math.abs(remaining) + " characters";
			$(this).next(".char-counter").css("color", "red");
		} else {
			$(this).next(".char-counter").css("color", "#666");
		}

		$(this).next(".char-counter").text(counterText);
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
			alert("Please select a file to import.");
			e.preventDefault();
			return false;
		}

		// Validate campaign selection
		var campaignOption = $('input[name="campaign_option"]:checked').val();
		if (campaignOption === "existing") {
			var existingCampaign = $("#existing_campaign").val();
			if (!existingCampaign) {
				alert("Please select an existing campaign.");
				e.preventDefault();
				$("#existing_campaign").focus();
				return false;
			}
		} else {
			var newCampaignName = $("#new_campaign_name").val().trim();
			if (!newCampaignName) {
				alert("Please enter a name for the new campaign.");
				e.preventDefault();
				$("#new_campaign_name").focus();
				return false;
			}
		}

		// Check if email column is selected (required)
		var emailColumn = $("#email_column").val();
		if (!emailColumn) {
			e.preventDefault();
			alert("Please select an Email column. This field is required.");
			$("#email_column").focus();
			return false;
		}

		// Validate file size (max 10MB)
		if (file.size > 10 * 1024 * 1024) {
			alert("File size must be less than 10MB.");
			e.preventDefault();
			return false;
		}

		// Validate file extension
		var allowedExtensions = ["xlsx", "xls", "csv"];
		var fileExtension = file.name.split(".").pop().toLowerCase();

		if (allowedExtensions.indexOf(fileExtension) === -1) {
			alert("Please select a valid file format (.xlsx, .xls, .csv).");
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
				alert("Please enter a campaign name.");
				$('input[name="campaign_name"]').focus();
				e.preventDefault();
				return false;
			}

			if (!emailSubject) {
				alert("Please enter an email subject.");
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

			// Get content from WordPress editor
			var emailContent = "";
			if (typeof tinyMCE !== "undefined" && tinyMCE.get("email_content")) {
				emailContent = tinyMCE.get("email_content").getContent();
			} else {
				emailContent = $("#email_content").val();
			}

			if (!emailContent || emailContent.trim() === "") {
				alert("Please enter email content.");
				if (typeof tinyMCE !== "undefined" && tinyMCE.get("email_content")) {
					tinyMCE.get("email_content").focus();
				} else {
					$("#email_content").focus();
				}
				e.preventDefault();
				return false;
			}

			console.log(
				"Campaign form submission - Email content length:",
				emailContent.length
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
});
