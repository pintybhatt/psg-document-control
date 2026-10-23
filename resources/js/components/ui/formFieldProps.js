export const formFieldProps = {
    id: { type: String, default: undefined },
    label: { type: String, required: true },
    helper: { type: String, default: '' },
    error: { type: String, default: '' },
    errorKey: { type: String, default: '' },
    required: Boolean,
};
