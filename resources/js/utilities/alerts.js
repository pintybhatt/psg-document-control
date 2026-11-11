import Swal from 'sweetalert2';

const buttonBase = 'inline-flex min-h-10 min-w-28 items-center justify-center rounded-xl border px-5 py-2 text-sm font-semibold';

export const swalClasses = {
    popup: 'rounded-3xl! px-2 pb-6',
    title: 'text-xl! font-semibold text-slate-900!',
    htmlContainer: 'text-sm! leading-6 text-slate-600!',
    actions: 'gap-3',
    confirmButton: `${buttonBase} border-teal-700 bg-teal-700 text-white shadow-md shadow-teal-700/20 hover:bg-teal-800`,
    cancelButton: `${buttonBase} border-slate-300 bg-white text-slate-700 hover:bg-slate-100`,
};

export const swalDangerClasses = {
    ...swalClasses,
    confirmButton: `${buttonBase} border-red-700 bg-red-700 text-white shadow-md shadow-red-700/20 hover:bg-red-800`,
};

/** Short success popup shown after data is saved. Closes itself. */
export function showSuccess(title, text = '') {
    return Swal.fire({
        icon: 'success',
        title,
        text,
        timer: 2200,
        timerProgressBar: true,
        showConfirmButton: false,
        customClass: swalClasses,
    });
}
