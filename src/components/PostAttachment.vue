<template>
	<masonry>
		<div v-for="(item,idx) in attachments" :key="idx">
			<img :src="OC.generateUrl('/apps/social/document/get/resized?id=' + item.id)" />
		</div>
	</masonry>
</template>

<script>

export default {
	name: 'PostAttachment',
	components: {
	},
	mixins: [],
	props: {
		attachments: Array,
	},
	data() {
		return {
		}
	},
	computed: {
	},
	mounted() {
	},
	methods: {
	}
}
</script>

<style scoped lang="scss">
       .post-attachment {
               display: flex;
               flex-grow: 1;
       }
</style>

