var basePath;
var Dropzone;
var dzClosure;
var ReportListBuilder;
Dropzone.autoDiscover = false;
jQuery(document).ready(function ($) {
    /* fix the Firefox bug; text field remains populated after user has clicked the "Send reply" button in the chat discussion panel. */
    $('#update_text').val("");

    var caseId = $('.row #case_id').val();
    $.fn.dataTable.moment('d-m-YYYY');

    $('#casedocuments').dataTable({
        //dom: See https://datatables.net/reference/option/dom
        "dom": 'tip',
        "iDisplayLength": 5,
        "responsive": true,
        "deferred": true,
        "buttons": ['pageLength'],
        "ordering": false
    });

    var messageCount = $('.chat-discussion .message').length;
    if (messageCount === 0) {
        $('.chat-discussion').css('min-height', '50px');
    } else {
        $('.chat-discussion').css('min-height', (messageCount * 100) + 'px');
    }

    addCaseUpdate(caseId);

    if ($('.chat-discussion .message').length < 1) {
        $('.chatDiscussionPanel').addClass('panel-collapse');
        $('.chatDiscussionPanel .panel-body').hide();
        $('.chatDiscussionPanel .panel-footer').hide();
    }

    /*
     * An instance of Drop Zone offers upload facility for an enquiry.
     */
    var maxFilesPerUpload = 1;

    /*
     * "viewCaseDropzone" is the camelized version of the div's ID
     */
    $("#view-case-dropzone").dropzone({
        url: 'addFiles',
        acceptedFiles: "image/jpeg,image/png,image/gif,.pdf,.doc,.docx,.xls,.xlsx,text/plain",
        autoProcessQueue: false,
        uploadMultiple: true,
        parallelUploads: maxFilesPerUpload,
        addRemoveLinks: true,
        /*
         * maxFilesize is 10MB
         */
        maxFilesize: 10,
        dictDefaultMessage: '<i class="fa fa-upload grey"><br>Choose a file or drop it here</i>',
        dictFileTooBig: "File is too big. Max size: {{maxFilesize}}MB.",

        /**
         * accept is a function that gets a file and a done function as parameter.
         * If the done function is invoked without a parameter, the file will be processed.
         * If you pass an error message it will be displayed and the file will not be uploaded.
         * This function will not be called if the file is too big or doesn't match the mime types.
         */
        accept: function (file, done) {
            var thumbnail = $('.dropzone .dz-preview.dz-file-preview .dz-image:last');

            switch (file.type) {
                /* PDF */
                case 'application/pdf':
                    thumbnail.addClass('pdfThumbnail');
                    break;

                /* DOC */
                /* DOCX */
                case 'application/msword':
                case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                    thumbnail.addClass('wordThumbnail');
                    break;

                /* XLS */
                /* XLSX */
                case 'application/vnd.ms-excel':
                case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                    thumbnail.addClass('excelThumbnail');
                    break;

                case 'text/plain':
                    thumbnail.addClass('textThumbnail');
                    break;

                default:
                    thumbnail.addClass('blankThumbnail');
                    break;
            }

            done();
        },

        init: function () {
            dzClosure = this;
            // Makes sure that 'this' is understood inside the functions below.

            $(".dz-hidden-input").prop("title", "upload");

            //send all the form data along with the files:
            this.on('sendingmultiple', function (data, xhr, formData) {
                $.blockUI({message: '<div style="font-size: 18px; font-weight:600;">Processing...</div>'});
                formData.append('file_count', $('.dz-filename').length);
                formData.append('case_id', $('#for_case_id').val());

            });

            // for Dropzone to process the queue (instead of default form behavior):
            document.getElementById('uploadSubmit').addEventListener('click', function (e) {

                // Make sure that the form isn't actually being sent.
                e.preventDefault();
                e.stopPropagation();

                //if necessary, clear error messages from the previous upload attempt
                $('.case .alert-danger').addClass('hide');

                //ensure an appropriate number of files are being uploaded
                var fileCount = $('.dz-filename').length;

                if ( fileCount <= maxFilesPerUpload) {

                    if (fileCount > 0) {
                        dzClosure.processQueue();
                    } else {
                        $('.case .alert-danger').removeClass('hide');
                        $('.case .alert-danger').html('No file uploaded.  To try again, drop a file into the region below then click the \'Upload\' button.');
                    }

                } else {
                    $('.case .alert-danger').removeClass('hide');
                    $('.case .alert-danger').html('Remove some files from the drop-zone ( upload at most ' + maxFilesPerUpload + ' at a time). Then click the \'Upload\' button.');
                }
            });

            this.on('successmultiple', function (files, response) {
                $.unblockUI();

                if (!response.result) {
                    $('.case .alert-danger').removeClass('hide');
                    $('.case .alert-danger').html('It seems that VTIS has encountered a problem saving enquiries data involving file upload. Please try again. If the problem persists, please contact the system administrator.');
                } else {
                    location.href = response.case_id;
                }
            });

            /**
             * An error occurred. The errorMessage is second parameter.
             */
            this.on('error', function (file, response) {
                switch (file.type) {
                    case 'application/pdf':
                    case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                    case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                    case 'text/plain':
                    case 'image/*':
                        break;
                    default:
                        $('.case #uploadForm #view-case-dropzone .dz-error-message').css("opacity", 1);
                        break;
                }

            });
        }
    });
    //end of Dropzone code

    handleEndCase(caseId);

   /*
   Knowledge base elements come in via ajax, packed in json, from the Report server.
   The twig template receives the contents, and renders it as an html table.
   The Jquery document ready invocation stimulates the following DOM manipulation
   so that the KB object is available via drill-through from its list item
   */
    const redrawKB = function (reportLocation, responsive) {
        const $columnIndexOfKBIds        = $(reportLocation + ' thead tr th.id'    ).index();
        const $columnIndexOfKBnames      = $(reportLocation + ' thead tr th.name'  ).index();
        const $columnIndexForActionIcons = $(reportLocation + ' thead tr th.action').index();
        const $actionIcons = $(reportLocation + ' tbody tr td.action:visible, li[data-dtr-index="' + $columnIndexForActionIcons + '"] span.dtr-data');
        const $kbNames     = $(reportLocation + ' tbody tr td.name,           li[data-dtr-index="' + $columnIndexOfKBnames +      '"] span.dtr-data');
        const $kbIds       = $(reportLocation + ' tbody tr td.id,             li[data-dtr-index="' + $columnIndexOfKBIds +        '"] span.dtr-data');

        const addActions = function ($actionEl, $kbId, $kbName) {
            $actionEl.html('');
            let $view = $('<i>');
            $view.attr('onclick', "javascript: location.href='" + basePath + "knowledgebase/faqs/" + $kbId + "'");
            $view.attr('title', 'View article');
            $view.addClass('fa fa-eye pink');
            $actionEl.append($view);
        };

        if($kbIds.length > 0) {
            let i = 0;

            for (i = 0; i < $actionIcons.length; i++) {
                let $cur_actionEl = $($actionIcons[i]);
                let $parent;
                let $cur_kbId;
                let $cur_kbName;

                if (responsive) {
                    $parent     = $cur_actionEl.parent().parent().parent().parent().prev();
                    $cur_kbId   = $parent.find('.id').text();
                    $cur_kbName = $parent.find('.name').text();
                } else {
                    $cur_kbId   = $($kbIds[i]).text();
                    $cur_kbName = $($kbNames[i]).text();
                }

                addActions($cur_actionEl, $cur_kbId, $cur_kbName);
            }
        }

        // Open responsive table on click of + icon
        $('.firstIcon').click(function () {
            setTimeout( function(){ redrawKB(reportLocation, true); }, 100);
        });
    };

    const caseKB_columnDefs = function (reportLocation) {
        const $columnIndex_kbIds       = $(reportLocation + ' thead tr th.id'    ).index();
        const $columnIndex_kbNames     = $(reportLocation + ' thead tr th.name'  ).index();
        const $columnIndex_actionIcons = $(reportLocation + ' thead tr th.action').index();

        return  [
            {className: "id hide",   "targets": [$columnIndex_kbIds]},
            {className: "firstIcon", "targets": [0]},
            {className: "name",      "targets": [$columnIndex_kbNames]},
            {className: "action alignCenter", "targets": [$columnIndex_actionIcons]}
        ];

    };

    const kbListOptions = {
        /*dom: See https://datatables.net/reference/option/dom */
        "dom": 'tip',
        "iDisplayLength": 5,
        "responsive": true,
        "deferred": true,
        "ordering": false,
    };

    const caseKB_ReportBuilder = new ReportListBuilder();
    const reportName = '/Portal Reports/CaseFAQs&limit=100&case_id=' + caseId;
    const reportLocation = '#caseKBtable';
    caseKB_ReportBuilder.build(reportName, reportLocation, redrawKB, caseKB_columnDefs, [5], kbListOptions);
});

/**
 * User has clicked the "Send reply" button in the chat discussion panel.
 */
function addCaseUpdate(caseId) {
    jQuery('#caseUpdateReply').click(function () {
        if (jQuery('#update_text').val() === '') {
            return false;
        }

        jQuery.blockUI({message: '<div style="font-size: 18px; font-weight:600;">Processing...</div>'});
        jQuery('body').scrollTop(0);

        jQuery.post('addCaseUpdate',
            {
                case_id: caseId,
                update_text: jQuery('#update_text').val()
            },
            function (response) {
                location.reload();
            }
        );

        return false;
    });
}

/**
 * User has clicked the "Enquiry resolved" button in the chat discussion panel.
 *
 * Post captured json data back to Controller::endCase
 * @uses jQuery.post(URL,data,function(data,status,xhr),dataType);
 *
 */
function handleEndCase(caseId)
{
    jQuery('#endCase').click(function () {
        jQuery.blockUI( { message: '<div style="font-size: 18px; font-weight:600;">Processing...</div>'} );
        jQuery.post( 'endCase', { case_id: caseId }).done(function() { location.reload(true); });
        return false;
    });
}
