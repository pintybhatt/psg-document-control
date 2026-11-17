import angleLeft from '@iconify-icons/flowbite/angle-left-outline';
import angleRight from '@iconify-icons/flowbite/angle-right-outline';
import archive from '@iconify-icons/flowbite/archive-outline';
import arrowLeft from '@iconify-icons/flowbite/arrow-left-outline';
import arrowRight from '@iconify-icons/flowbite/arrow-right-outline';
import arrowRightToBracket from '@iconify-icons/flowbite/arrow-right-to-bracket-outline';
import ban from '@iconify-icons/flowbite/ban-outline';
import building from '@iconify-icons/flowbite/building-outline';
import check from '@iconify-icons/flowbite/check-outline';
import checkCircle from '@iconify-icons/flowbite/check-circle-outline';
import clipboardCheck from '@iconify-icons/flowbite/clipboard-check-outline';
import clockArrow from '@iconify-icons/flowbite/clock-arrow-outline';
import close from '@iconify-icons/flowbite/close-outline';
import closeCircle from '@iconify-icons/flowbite/close-circle-outline';
import download from '@iconify-icons/flowbite/download-outline';
import edit from '@iconify-icons/flowbite/edit-outline';
import eye from '@iconify-icons/flowbite/eye-outline';
import fileCheck from '@iconify-icons/flowbite/file-check-outline';
import fileCopy from '@iconify-icons/flowbite/file-copy-outline';
import fileExport from '@iconify-icons/flowbite/file-export-outline';
import filePen from '@iconify-icons/flowbite/file-pen-outline';
import filter from '@iconify-icons/flowbite/filter-outline';
import floppyDisk from '@iconify-icons/flowbite/floppy-disk-outline';
import link from '@iconify-icons/flowbite/link-outline';
import list from '@iconify-icons/flowbite/list-outline';
import lock from '@iconify-icons/flowbite/lock-outline';
import paperPlane from '@iconify-icons/flowbite/paper-plane-outline';
import plus from '@iconify-icons/flowbite/plus-outline';
import printer from '@iconify-icons/flowbite/printer-outline';
import refresh from '@iconify-icons/flowbite/refresh-outline';
import reply from '@iconify-icons/flowbite/reply-outline';
import search from '@iconify-icons/flowbite/search-outline';
import shieldCheck from '@iconify-icons/flowbite/shield-check-outline';
import trash from '@iconify-icons/flowbite/trash-bin-outline';
import truck from '@iconify-icons/flowbite/truck-outline';
import undo from '@iconify-icons/flowbite/undo-outline';
import upload from '@iconify-icons/flowbite/upload-outline';
import userAdd from '@iconify-icons/flowbite/user-add-outline';
import users from '@iconify-icons/flowbite/users-outline';
import en from '../../localization/en.js';
import lo from '../../localization/lo.js';

/** Icons a button can request by name: <UiButton icon="save">. */
export const buttonIcons = {
    archive, back: arrowLeft, ban, building, check, 'check-circle': checkCircle, clipboard: clipboardCheck, close, 'close-circle': closeCircle,
    copy: fileCopy, download, edit, export: fileExport, filter, history: clockArrow, link, list, lock, login: arrowRightToBracket,
    next: angleRight, previous: angleLeft, print: printer, refresh, reply, save: floppyDisk, search, send: paperPlane, shield: shieldCheck,
    'file-check': fileCheck, 'file-pen': filePen, plus, forward: arrowRight, trash, truck, undo, upload, 'user-add': userAdd, users, view: eye,
};

// Icons for specific button labels (translation keys).
const byKey = {
    uiCancel: close, reportClose: close, outgoingClosePreview: close, incomingApplyFilters: filter, uiConfirm: check, uiPrimary: check,
    rbacDetails: eye, backToRegister: arrowLeft, uiPrevious: angleLeft, userPrevious: angleLeft, numberingNextPreview: angleRight,
    storageSave: floppyDisk, rbacAssignRole: userAdd, membershipAssign: userAdd, reportPreviewDetailPdf: eye, storageDownload: download,
    storagePreview: eye, storageUpload: upload, storageReplace: upload, outgoingRegister: checkCircle, incomingRegister: checkCircle,
    'outgoingAction-dispatch': paperPlane, 'outgoingAction-delivery': truck, 'outgoingAction-correct_dispatch': filePen, 'outgoingAction-reopen': undo,
    outgoingSaveOperation: floppyDisk, outgoingFindIncoming: search, numberingSave: floppyDisk, orgCompanies: building, numberingCreate: plus,
    numberingHistory: clockArrow, numberingPreview: eye, outgoingRemoveRecipient: trash, outgoingSave: floppyDisk, outgoingEdit: edit,
    orgCreate: plus, orgSave: floppyDisk, outgoingCreate: plus, outgoingRemoveIncomingLink: closeCircle, rbacFindCandidates: search,
    'document-search': search, rbacSaveRole: floppyDisk, rbacSwitchOwnerScope: refresh, rbacUseRecommended: check, uiSecondary: arrowRight,
    uiOpenDialog: filePen, uiOpenDangerDialog: ban, uiValidate: checkCircle, uiDanger: trash, uiDisabled: ban, rbacCreateRole: plus,
    templateFindSource: search, templateRetire: archive, templateCreate: plus, profilePasswordSave: lock, templatePublish: checkCircle,
    userSave: floppyDisk, reportDownloadPdf: download, userCreate: userAdd, userFind: search, correspondenceCorrectDetails: filePen,
    correspondenceSaveCorrection: floppyDisk, controlledFindReplacement: search, 'controlledAction-revisions': fileCopy,
    'controlledAction-status': refresh, approvalRefresh: refresh, masterCreate: plus, masterSave: floppyDisk, approvalReject: closeCircle,
    approvalSubmit: paperPlane, approvalApprove: checkCircle, 'controlledAction-release': fileCheck, confidentialityFindDocuments: search,
    confidentialityManageAccess: shieldCheck, confidentialityGrantClearance: shieldCheck, workflowHistory: clockArrow, confidentialityFindUsers: search,
    confidentialityGrantDocument: shieldCheck, controlledEdit: edit, 'controlledAction-register': checkCircle, 'controlledAction-approvals': clipboardCheck,
    controlledCreate: plus, 'controlledAction-reviews': clipboardCheck, incomingClose: archive, incomingReopen: undo, incomingLink: link,
    incomingUpdateHandling: floppyDisk, incomingFollowUp: reply, incomingFind: search, numberingPolicyActivate: checkCircle,
    numberingXpplReview: clipboardCheck, membershipFind: search, incomingUnlink: closeCircle, authLogout: arrowRightToBracket,
    'storageAction-checkout': arrowRight, 'storageAction-return': undo, storageAddCopy: plus, controlledRetryLoad: refresh,
    'storageAction-transfer': users, incomingSave: floppyDisk, incomingEdit: edit, incomingCreate: plus, 'storageAction-inventory': list,
    'storageAction-correct': filePen,
};

// Fallback for other labels, matched against the translation key (first match wins).
const byPattern = [
    [/cancel|close/i, close], [/save|update/i, floppyDisk], [/edit|correct/i, edit], [/delete|remove/i, trash], [/retire|archive/i, archive],
    [/create|add|new/i, plus], [/find|search/i, search], [/download/i, download], [/export/i, fileExport], [/upload|replace/i, upload],
    [/preview|view|details|open/i, eye], [/reject|revoke|deactivate|unlink/i, closeCircle],
    [/approve|register|confirm|activate|publish|validate|release/i, checkCircle], [/refresh|retry|reload|switch/i, refresh],
    [/previous|back/i, angleLeft], [/next/i, angleRight], [/history/i, clockArrow], [/filter|apply/i, filter], [/print/i, printer],
    [/submit|send|dispatch/i, paperPlane], [/assign|grant|member/i, userAdd], [/logout|login|signin|sign-in/i, arrowRightToBracket],
    [/link/i, link], [/reopen|return|undo|restore/i, undo],
];

function iconForKey(key) {
    if (byKey[key]) { return byKey[key]; }
    return byPattern.find(([pattern]) => pattern.test(key))?.[1] ?? null;
}

// Buttons render translated text, so map each translation (English and Lao) back to an icon.
const byText = new Map();
for (const dictionary of [en, lo]) {
    for (const [key, text] of Object.entries(dictionary)) {
        if (typeof text !== 'string' || text.includes('{')) { continue; }
        const icon = iconForKey(key);
        const normalized = text.trim().toLowerCase();
        if (icon && normalized && (!byText.has(normalized) || byKey[key])) { byText.set(normalized, icon); }
    }
}

/** Pick an icon for a button from its visible label, falling back on the button's role. */
export function iconForButton(label, { type = 'button', variant = 'primary' } = {}) {
    const found = byText.get(String(label ?? '').trim().toLowerCase());
    if (found) { return found; }
    if (type === 'submit') { return floppyDisk; }
    if (variant === 'danger') { return ban; }
    return variant === 'secondary' ? arrowRight : check;
}
