var api = require('../services/api');
var store = require('../store/index');
var dateUtil = require('../utils/date');

/**
 * Technician work queue page.
 * Displays only assigned jobs with accept, work notes, and complete controls.
 * No pricing fields are visible.
 */

/**
 * Fetch and render the technician's assigned jobs.
 */
function fetchQueue(listContainer) {
  listContainer.innerHTML =
    '<div class="fieldops-empty">' +
      '<i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop" style="font-size:30px;"></i>' +
    '</div>';

  // Fetch both assigned and in_progress jobs so accepted jobs remain visible
  Promise.all([
    api.get('/orders', { status: 'assigned' }),
    api.get('/orders', { status: 'in_progress' }),
  ]).then(function (results) {
    var assigned = (results[0].data && results[0].data.items) || [];
    var inProgress = (results[1].data && results[1].data.items) || [];
    var jobs = assigned.concat(inProgress);

    if (jobs.length === 0) {
      listContainer.innerHTML =
        '<div class="fieldops-empty">' +
          'No jobs assigned to you at this time.' +
        '</div>';
      return;
    }

    renderJobList(listContainer, jobs);
  }).catch(function (err) {
    listContainer.innerHTML =
      '<div class="fieldops-error">' +
        'Failed to load queue: ' + (err.message || 'Unknown error') +
      '</div>';
  });
}

/**
 * Render the list of jobs.
 */
function renderJobList(container, jobs) {
  var html = '';
  for (var i = 0; i < jobs.length; i++) {
    var job = jobs[i];
    var created = job.created_at ? dateUtil.toLocalDisplay(job.created_at) : '';
    html +=
      '<div class="layui-card" style="margin-bottom:12px;" data-job-id="' + job.id + '">' +
        '<div class="layui-card-header">' +
          '<strong>Order #' + (job.order_no || job.order_id || '') + '</strong>' +
          ' <span class="layui-badge ' + jobStatusBadge(job.status) + '">' + (job.status || '') + '</span>' +
          '<span style="float:right;color:#999;font-size:12px;">' + created + '</span>' +
        '</div>' +
        '<div class="layui-card-body">' +
          '<p><strong>Customer:</strong> ' + (job.customer_name || '') + '</p>' +
          '<p><strong>Service:</strong> ' + (job.service_name || '') + '</p>' +
          (job.description ? '<p><strong>Description:</strong> ' + job.description + '</p>' : '') +
          '<div style="margin-top:12px;" class="job-actions" data-job-id="' + job.id + '" data-status="' + (job.status || '') + '">' +
            buildJobActions(job) +
          '</div>' +
        '</div>' +
      '</div>';
  }
  container.innerHTML = html;
  bindJobActions(container);
}

/**
 * Map job status to a badge class.
 */
function jobStatusBadge(status) {
  switch (status) {
    case 'assigned': return 'layui-bg-orange';
    case 'accepted': return 'layui-bg-blue';
    case 'in_progress': return 'layui-bg-blue';
    case 'completed': return 'layui-bg-green';
    default: return '';
  }
}

/**
 * Build action buttons based on job status.
 */
function buildJobActions(job) {
  var html = '';
  var status = job.status || '';

  if (status === 'assigned') {
    html += '<button class="layui-btn layui-btn-sm" data-action="accept" data-job-id="' + job.id + '">Accept</button>';
  }

  if (status === 'accepted' || status === 'in_progress') {
    html +=
      '<div class="layui-form-item" style="display:inline-flex;gap:8px;align-items:center;margin:0;">' +
        '<input type="text" class="layui-input" style="width:300px;" placeholder="Work notes..." id="job-notes-' + job.id + '">' +
        '<button class="layui-btn layui-btn-sm layui-btn-normal" data-action="save-notes" data-job-id="' + job.id + '">Save Notes</button>' +
        '<button class="layui-btn layui-btn-sm layui-btn-warm" data-action="complete" data-job-id="' + job.id + '">Complete</button>' +
      '</div>';
  }

  if (status === 'completed') {
    html += '<span style="color:#5FB878;"><i class="layui-icon layui-icon-ok-circle"></i> Job completed</span>';
  }

  return html;
}

/**
 * Bind click handlers for job action buttons.
 */
function bindJobActions(container) {
  container.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-action]');
    if (!btn) return;

    var action = btn.getAttribute('data-action');
    var jobId = btn.getAttribute('data-job-id');

    if (action === 'accept') {
      acceptJob(jobId, btn, container);
    } else if (action === 'save-notes') {
      saveNotes(jobId, btn);
    } else if (action === 'complete') {
      completeJob(jobId, btn, container);
    }
  });
}

/**
 * Accept a job assignment.
 */
function acceptJob(jobId, btn, listContainer) {
  btn.disabled = true;
  btn.textContent = 'Accepting...';

  api.post('/orders/' + jobId + '/accept', {})
    .then(function () {
      if (typeof layui !== 'undefined' && layui.layer) {
        layui.layer.msg('Job accepted.', { icon: 1 });
      }
      fetchQueue(listContainer);
    })
    .catch(function (err) {
      btn.disabled = false;
      btn.textContent = 'Accept';
      if (typeof layui !== 'undefined' && layui.layer) {
        layui.layer.msg(err.message || 'Failed to accept job.', { icon: 2 });
      }
    });
}

/**
 * Save work notes for a job.
 */
function saveNotes(jobId, btn) {
  var notesInput = document.getElementById('job-notes-' + jobId);
  var notes = notesInput ? notesInput.value.trim() : '';

  if (!notes) {
    if (typeof layui !== 'undefined' && layui.layer) {
      layui.layer.msg('Please enter work notes.', { icon: 0 });
    }
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Saving...';

  api.post('/orders/' + jobId + '/work-notes', { note: notes })
    .then(function () {
      if (typeof layui !== 'undefined' && layui.layer) {
        layui.layer.msg('Notes saved.', { icon: 1 });
      }
      btn.disabled = false;
      btn.textContent = 'Save Notes';
    })
    .catch(function (err) {
      btn.disabled = false;
      btn.textContent = 'Save Notes';
      if (typeof layui !== 'undefined' && layui.layer) {
        layui.layer.msg(err.message || 'Failed to save notes.', { icon: 2 });
      }
    });
}

/**
 * Complete a job.
 */
function completeJob(jobId, btn, listContainer) {
  var notesInput = document.getElementById('job-notes-' + jobId);
  var notes = notesInput ? notesInput.value.trim() : '';

  btn.disabled = true;
  btn.textContent = 'Completing...';

  api.post('/orders/' + jobId + '/complete', {})
    .then(function () {
      if (typeof layui !== 'undefined' && layui.layer) {
        layui.layer.msg('Job completed.', { icon: 1 });
      }
      fetchQueue(listContainer);
    })
    .catch(function (err) {
      btn.disabled = false;
      btn.textContent = 'Complete';
      if (typeof layui !== 'undefined' && layui.layer) {
        layui.layer.msg(err.message || 'Failed to complete job.', { icon: 2 });
      }
    });
}

/**
 * Render the technician queue page into the given container.
 *
 * @param {HTMLElement} container
 */
function render(container) {
  if (!container) return;

  var html =
    '<div style="margin-bottom:16px;">' +
      '<button class="layui-btn layui-btn-sm" id="tech-queue-refresh-btn">' +
        '<i class="layui-icon layui-icon-refresh-3"></i> Refresh' +
      '</button>' +
    '</div>' +
    '<div id="tech-queue-list"></div>';

  container.innerHTML = html;

  var listContainer = document.getElementById('tech-queue-list');

  // Refresh button
  var refreshBtn = document.getElementById('tech-queue-refresh-btn');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', function () {
      fetchQueue(listContainer);
    });
  }

  // Initial load
  fetchQueue(listContainer);
}

module.exports = {
  render: render,
};
