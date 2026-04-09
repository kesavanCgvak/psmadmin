/* global $ */
(function () {
  function toNumberOrEmpty(value) {
    if (value === null || value === undefined) return '';
    var trimmed = String(value).trim();
    if (trimmed === '') return '';
    var num = Number(trimmed);
    return Number.isFinite(num) ? num : '';
  }

  function openModalFromButton(btn) {
    var $btn = $(btn);
    var companyName = $btn.data('companyName') || 'Company';
    var avg = $btn.data('userAvg');
    var count = $btn.data('userCount');
    var override = $btn.data('override');
    var postUrl = $btn.data('postUrl');

    $('#ratingOverrideCompanyName').text(companyName);
    $('#ratingOverrideUserAvg').text(avg !== null && avg !== undefined ? Number(avg).toFixed(1) : '0.0');
    $('#ratingOverrideUserCount').text(count !== null && count !== undefined ? String(count) : '0');

    $('#ratingOverrideInput').val(toNumberOrEmpty(override));
    $('#ratingOverrideReason').val('');

    $('#ratingOverrideForm').attr('action', postUrl);
    $('#ratingOverrideModal').modal('show');
  }

  $(document).on('click', '.js-rating-override-edit', function (e) {
    e.preventDefault();
    openModalFromButton(this);
  });
})();

